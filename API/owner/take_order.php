<?php
// api/owner/take_order.php
// POST endpoint: Places a new order for a specific restaurant
// Protected by JWT - only superadmin allowed
// Expects JSON body: { "restaurant_id": int, "table_identifier": string, "items": [ {item_id, quantity, notes}, ... ] }

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

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
    echo json_encode(['success' => false, 'error' => 'Server configuration error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST allowed']);
    exit;
}

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
if ($input === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$restaurant_id    = (int)($input['restaurant_id'] ?? 0);
$table_identifier = trim($input['table_identifier'] ?? '');
$items            = $input['items'] ?? [];

if ($restaurant_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'restaurant_id is required']);
    exit;
}
if (empty($table_identifier)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Table identifier (number/name/phone) is required']);
    exit;
}
if (empty($items) || !is_array($items)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Items array is required and must not be empty']);
    exit;
}

// ─── Token validation ────────────────────────────────────────────────
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token required']);
    exit;
}

$token = $matches[1];

try {
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $user = (array)$decoded->data;

    if (!isset($user['role']) || $user['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only owner allowed']);
        exit;
    }

    $user_id = (int)$user['user_id'];

    // ─── Verify restaurant belongs to this owner ───────────────────────
    $check = $conn->prepare("
        SELECT chain_id 
        FROM restaurants 
        WHERE id = ?
    ");
    if ($check === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database prepare error (restaurant check): ' . $conn->error]);
        exit;
    }
    $check->bind_param("i", $restaurant_id);
    $check->execute();
    $res = $check->get_result();

    if ($res->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid restaurant']);
        exit;
    }

    $rest_row = $res->fetch_assoc();
    $chain_id = (int)$rest_row['chain_id'];
    $check->close();

    // Confirm owner has this chain
    $owner_check = $conn->prepare("
        SELECT id FROM users 
        WHERE id = ? AND chain_id = ? AND role = 'superadmin'
        LIMIT 1
    ");
    if ($owner_check === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database prepare error (owner check): ' . $conn->error]);
        exit;
    }
    $owner_check->bind_param("ii", $user_id, $chain_id);
    $owner_check->execute();
    if ($owner_check->get_result()->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized restaurant']);
        exit;
    }
    $owner_check->close();

    // ─── Validate items & fetch prices + check stock ─────────────────────
    $placeholders = str_repeat('?,', count($items) - 1) . '?';
    $item_stmt = $conn->prepare("
        SELECT id, item_name, price 
        FROM menu_items 
        WHERE id IN ($placeholders) AND restaurant_id = ? AND status = 'available'
    ");
    if ($item_stmt === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Prepare failed (menu items): ' . $conn->error]);
        exit;
    }

    $item_ids = array_column($items, 'item_id');
    $types = str_repeat('i', count($item_ids)) . 'i';
    $params = array_merge($item_ids, [$restaurant_id]);

    $item_stmt->bind_param($types, ...$params);
    $item_stmt->execute();
    $result = $item_stmt->get_result();

    $prices = [];
    while ($row = $result->fetch_assoc()) {
        $prices[$row['id']] = [
            'name'  => $row['item_name'],
            'price' => (float)$row['price']
        ];
    }
    $item_stmt->close();

    // Validate all items exist and are available
    $total = 0.0;
    $order_items = [];
    $stock_deduction = [];

    foreach ($items as $item) {
        $item_id = (int)($item['item_id'] ?? 0);
        $qty = max(1, (int)($item['quantity'] ?? 1));
        $notes = trim($item['notes'] ?? '');

        if (!isset($prices[$item_id])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Item ID $item_id not found or unavailable"]);
            exit;
        }

        $name = $prices[$item_id]['name'];
        $price = $prices[$item_id]['price'];

        $total += $qty * $price;
        $order_items[] = [
            'item_id'   => $item_id,
            'quantity'  => $qty,
            'notes'     => $notes,
            'item_name' => $name
        ];

        $stock_deduction[$name] = ($stock_deduction[$name] ?? 0) + $qty;
    }

    // ─── Begin transaction ───────────────────────────────────────────────
    $conn->begin_transaction();

    try {
        // Check & deduct stock
        foreach ($stock_deduction as $name => $needed) {
            $stock_stmt = $conn->prepare("
                SELECT quantity 
                FROM stock_inventory 
                WHERE restaurant_id = ? AND stock_name = ?
                FOR UPDATE
            ");
            $stock_stmt->bind_param("is", $restaurant_id, $name);
            $stock_stmt->execute();
            $stock_res = $stock_stmt->get_result();

            if ($stock_res->num_rows === 0) {
                throw new Exception("Stock item '$name' not found");
            }

            $stock_row = $stock_res->fetch_assoc();
            $current_stock = (int)$stock_row['quantity'];

            if ($current_stock < $needed) {
                throw new Exception("Insufficient stock for '$name' (needed $needed, available $current_stock)");
            }

            // Deduct stock
            $update_stmt = $conn->prepare("
                UPDATE stock_inventory 
                SET quantity = quantity - ? 
                WHERE restaurant_id = ? AND stock_name = ?
            ");
            $update_stmt->bind_param("iis", $needed, $restaurant_id, $name);
            $update_stmt->execute();
            $update_stmt->close();
            $stock_stmt->close();
        }

        // Insert order
        $order_stmt = $conn->prepare("
            INSERT INTO orders (
                restaurant_id, 
                table_number, 
                total_amount, 
                items, 
                order_by, 
                status, 
                created_at
            ) VALUES (?, ?, ?, ?, ?, 'preparing', ?)
        ");
        if ($order_stmt === false) {
            throw new Exception('Prepare failed (order insert): ' . $conn->error);
        }

        $items_json = json_encode($order_items);
        $now = nepali_date_time();
        $order_stmt->bind_param("isdiss", $restaurant_id, $table_identifier, $total, $items_json, $user_id, $now);
        $order_stmt->execute();
        $order_id = $conn->insert_id;
        $order_stmt->close();

        $conn->commit();

        echo json_encode([
            'success'  => true,
            'order_id' => $order_id,
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

} catch (\Firebase\JWT\ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token expired']);
} catch (\Firebase\JWT\SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

exit;