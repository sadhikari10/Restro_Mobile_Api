<?php
// api/owner/take_order.php
// POST - Places order from owner (superadmin) interface

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

// ─── Read JSON ───────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
if ($input === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$restaurant_id    = (int)($input['restaurant_id'] ?? 0);
$table_identifier = trim($input['table_identifier'] ?? '');
$items            = $input['items'] ?? [];

if ($restaurant_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'restaurant_id required']);
    exit;
}
if (empty($table_identifier)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Table / customer identifier required']);
    exit;
}
if (empty($items) || !is_array($items)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'items array required and non-empty']);
    exit;
}

// ─── JWT + role check ────────────────────────────────────────────────
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Bearer token required']);
    exit;
}
$token = $matches[1];

try {
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $user = (array)$decoded->data;

    if (!isset($user['role']) || $user['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Superadmin access only']);
        exit;
    }

    $user_id = (int)$user['user_id'];

    // ─── Verify restaurant belongs to this superadmin's chain ────────
    $stmt = $conn->prepare("
        SELECT chain_id 
        FROM restaurants 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Restaurant not found']);
        exit;
    }
    $chain_id = (int)$res->fetch_assoc()['chain_id'];
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT 1 
        FROM users 
        WHERE id = ? AND chain_id = ? AND role = 'superadmin'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $user_id, $chain_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized for this restaurant']);
        exit;
    }
    $stmt->close();

    // ─── Validate items belong to restaurant + get prices ────────────
    $item_ids = array_column($items, 'item_id');
    $placeholders = str_repeat('?,', count($item_ids) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT id, item_name, price 
        FROM menu_items 
        WHERE id IN ($placeholders) 
          AND restaurant_id = ? 
          AND status = 'available'
    ");
    $types = str_repeat('i', count($item_ids)) . 'i';
    $params = array_merge($item_ids, [$restaurant_id]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $menu_data = [];
    while ($row = $result->fetch_assoc()) {
        $menu_data[$row['id']] = [
            'name'  => $row['item_name'],
            'price' => (float)$row['price']
        ];
    }
    $stmt->close();

    if (count($menu_data) !== count($item_ids)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'One or more items do not belong to this restaurant or are unavailable'
        ]);
        exit;
    }

    // ─── Prepare order items & total ─────────────────────────────────
    $order_items   = [];
    $total_amount  = 0.0;
    $stock_to_check = [];

    foreach ($items as $it) {
        $item_id  = (int)($it['item_id'] ?? 0);
        $qty      = max(1, (int)($it['quantity'] ?? 1));
        $notes    = trim($it['notes'] ?? '');

        if (!isset($menu_data[$item_id])) continue; // already checked, but safety

        $price    = $menu_data[$item_id]['price'];
        $name     = $menu_data[$item_id]['name'];

        $total_amount += $price * $qty;

        $order_items[] = [
            'item_id'   => $item_id,
            'quantity'  => $qty,
            'notes'     => $notes
        ];

        $stock_to_check[$name] = ($stock_to_check[$name] ?? 0) + $qty;
    }

    if (empty($order_items)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No valid items']);
        exit;
    }

    // ─── Transaction ─────────────────────────────────────────────────
    $conn->begin_transaction();

    try {
        // Deduct stock ONLY if tracked
        foreach ($stock_to_check as $name => $needed) {
            $s = $conn->prepare("
                SELECT quantity 
                FROM stock_inventory 
                WHERE restaurant_id = ? AND stock_name = ?
                FOR UPDATE
            ");
            $s->bind_param("is", $restaurant_id, $name);
            $s->execute();
            $res = $s->get_result();

            if ($row = $res->fetch_assoc()) {
                // tracked → must have enough
                if ($row['quantity'] < $needed) {
                    throw new Exception("Insufficient stock for '$name' (needed $needed, available {$row['quantity']})");
                }
                $upd = $conn->prepare("
                    UPDATE stock_inventory 
                    SET quantity = quantity - ? 
                    WHERE restaurant_id = ? AND stock_name = ?
                ");
                $upd->bind_param("iis", $needed, $restaurant_id, $name);
                $upd->execute();
                $upd->close();
            }
            // not tracked → skip silently
            $s->close();
        }

        // Insert order
        $items_json = json_encode($order_items);
        $now = nepali_date_time();

        $stmt = $conn->prepare("
            INSERT INTO orders (
                restaurant_id, table_number, total_amount, items, 
                order_by, status, created_at
            ) VALUES (?, ?, ?, ?, ?, 'preparing', ?)
        ");
        $stmt->bind_param("isdsis", $restaurant_id, $table_identifier, $total_amount, $items_json, $user_id, $now);
        $stmt->execute();
        $order_id = $conn->insert_id;
        $stmt->close();

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