<?php
// api/admin/place_order.php
// Protected POST endpoint: places a new order and deducts stock only when tracked
// Allows staff, admin, superadmin roles

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');           // Change to your app's domain in production
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Dependencies
require_once '../../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once '../../Common/connection.php';  // brings $conn
require_once '../../Common/nepali_date.php'; // for nepali_date_time()

// JWT Secret
$JWT_SECRET = $_ENV['JWT_SECRET'] ?? null;
if (empty($JWT_SECRET)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server configuration error (missing JWT secret)']);
    exit;
}

// Only POST allowed
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

    if (!$userData || !isset($userData->role)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid token data']);
        exit;
    }

    $role = $userData->role;
    if (!in_array($role, ['staff', 'admin', 'superadmin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied - insufficient role']);
        exit;
    }

    $restaurant_id = (int)($userData->restaurant_id ?? 0);
    $staff_id = (int)($userData->user_id ?? 0);  // even for admin/superadmin, we still record who placed it

    if ($restaurant_id <= 0 || $staff_id <= 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid user or restaurant context']);
        exit;
    }

    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $table_identifier = trim($input['table_identifier'] ?? '');
    $items = $input['items'] ?? [];  // array of {item_id: int, quantity: int, notes: string}

    if (empty($table_identifier)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Table / Customer / Phone is required']);
        exit;
    }

    if (empty($items) || !is_array($items)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'At least one item is required']);
        exit;
    }

    // Validate items & calculate total (same as staff)
    $menu_data = [];
    $item_ids = array_column($items, 'item_id');
    if (!empty($item_ids)) {
        $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
        $stmt = $conn->prepare("
            SELECT id, item_name, price 
            FROM menu_items 
            WHERE id IN ($placeholders) AND restaurant_id = ?
        ");
        $types = str_repeat('i', count($item_ids)) . 'i';
        $params = array_merge($item_ids, [$restaurant_id]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $menu_data[$row['id']] = ['name' => $row['item_name'], 'price' => $row['price']];
        }
        $stmt->close();
    }

    $order_items = [];
    $total_amount = 0.0;

    foreach ($items as $item) {
        $item_id = (int)($item['item_id'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 1);
        $notes = trim($item['notes'] ?? '');

        if ($item_id <= 0 || $quantity <= 0 || !isset($menu_data[$item_id])) {
            continue; // skip invalid items
        }

        $price = $menu_data[$item_id]['price'];
        $subtotal = $price * $quantity;
        $total_amount += $subtotal;

        $order_items[] = [
            'item_id'   => $item_id,
            'quantity'  => $quantity,
            'notes'     => $notes
        ];
    }

    if (empty($order_items)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No valid items in order']);
        exit;
    }

    // Transaction: check/deduct stock only when tracked + insert order
    $conn->begin_transaction();

    try {
        foreach ($order_items as $oi) {
            $name = $menu_data[$oi['item_id']]['name'];
            $qty = $oi['quantity'];

            // Check if tracked
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
                    throw new Exception("Insufficient stock for $name: only {$stock_row['quantity']} available");
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

        // Insert order
        $items_json = json_encode($order_items);
        $created_at = nepali_date_time();

        $insert_stmt = $conn->prepare("
            INSERT INTO orders 
            (restaurant_id, table_number, items, total_amount, status, created_at, order_by) 
            VALUES (?, ?, ?, ?, 'preparing', ?, ?)
        ");
        $insert_stmt->bind_param("issdsi", $restaurant_id, $table_identifier, $items_json, $total_amount, $created_at, $staff_id);
        $insert_stmt->execute();
        $new_order_id = $insert_stmt->insert_id;
        $insert_stmt->close();

        $conn->commit();

        echo json_encode([
            'success'  => true,
            'order_id' => $new_order_id,
            'message'  => 'Order placed successfully'
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage()
        ]);
    }

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