<?php
// api/owner/take_order.php
// Protected POST endpoint: places a new order and deducts stock only when tracked
// Requires valid JWT (superadmin role) + restaurant_id in body

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once '../../Common/connection.php';
require_once '../../Common/nepali_date.php';

$JWT_SECRET = $_ENV['JWT_SECRET'] ?? null;
if (empty($JWT_SECRET)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server configuration error (missing JWT secret)']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST method is allowed']);
    exit;
}

try {
    // Extract Bearer Token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication token required']);
        exit;
    }

    $token = $matches[1];

    // Validate JWT
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $userData = $decoded->data ?? null;

    if (!$userData || !isset($userData->role) || $userData->role !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'superadmin role required']);
        exit;
    }

    $owner_id = (int)($userData->user_id ?? 0);

    // Read JSON payload
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $restaurant_id = (int)($input['restaurant_id'] ?? 0);
    $table_identifier = trim($input['table_identifier'] ?? '');
    $order_items = $input['items'] ?? [];

    if ($restaurant_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'restaurant_id is required']);
        exit;
    }

    if (empty($table_identifier)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Table number or customer identifier is required']);
        exit;
    }

    if (empty($order_items)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'At least one item is required']);
        exit;
    }

    $conn->begin_transaction();

    $total_amount = 0.0;
    $order_items_db = [];

    foreach ($order_items as $oi) {
        $item_id = (int)($oi['item_id'] ?? 0);
        $qty = (int)($oi['quantity'] ?? 1);
        $notes = trim($oi['notes'] ?? '');

        $stmt = $conn->prepare("
            SELECT item_name, price 
            FROM menu_items 
            WHERE id = ? AND restaurant_id = ? AND status = 'available'
        ");
        $stmt->bind_param("ii", $item_id, $restaurant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $price = (float)$row['price'];
            $subtotal = $price * $qty;
            $total_amount += $subtotal;

            $order_items_db[] = [
                'item_id'   => $item_id,
                'item_name' => $row['item_name'],
                'price'     => $price,
                'quantity'  => $qty,
                'notes'     => $notes
            ];
        } else {
            throw new Exception("Item ID $item_id not found or unavailable");
        }
        $stmt->close();

        // Stock deduction - identical to staff/admin
        $name_stmt = $conn->prepare("
            SELECT stock_name 
            FROM menu_items 
            WHERE id = ? AND restaurant_id = ?
        ");
        $name_stmt->bind_param("ii", $item_id, $restaurant_id);
        $name_stmt->execute();
        $name_res = $name_stmt->get_result();
        $name = $name_res->fetch_assoc()['stock_name'] ?? null;
        $name_stmt->close();

        if ($name) {
            $check_stmt = $conn->prepare("
                SELECT quantity 
                FROM stock_inventory 
                WHERE restaurant_id = ? AND stock_name = ?
                FOR UPDATE
            ");
            $check_stmt->bind_param("is", $restaurant_id, $name);
            $check_stmt->execute();
            $stock_result = $check_stmt->get_result();

            if ($stock_row = $stock_result->fetch_assoc()) {
                if ($stock_row['quantity'] < $qty) {
                    throw new Exception("Insufficient stock for $name");
                }

                $deduct_stmt = $conn->prepare("
                    UPDATE stock_inventory 
                    SET quantity = quantity - ? 
                    WHERE restaurant_id = ? AND stock_name = ?
                ");
                $deduct_stmt->bind_param("iis", $qty, $restaurant_id, $name);
                $deduct_stmt->execute();
                $deduct_stmt->close();
            }
            $check_stmt->close();
        }
    }

    $items_json = json_encode($order_items_db);
    $created_at = nepali_date_time();

    $insert_stmt = $conn->prepare("
        INSERT INTO orders 
        (restaurant_id, table_number, items, total_amount, status, created_at, order_by) 
        VALUES (?, ?, ?, ?, 'preparing', ?, ?)
    ");
    $insert_stmt->bind_param("issdsi", $restaurant_id, $table_identifier, $items_json, $total_amount, $created_at, $owner_id);
    $insert_stmt->execute();
    $new_order_id = $insert_stmt->insert_id;
    $insert_stmt->close();

    $conn->commit();

    echo json_encode([
        'success'  => true,
        'order_id' => $new_order_id,
        'message'  => 'Order placed successfully'
    ], JSON_NUMERIC_CHECK);

} catch (Exception $e) {
    if ($conn->in_transaction) $conn->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Firebase\JWT\ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token has expired']);
} catch (Firebase\JWT\SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token signature']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

exit;