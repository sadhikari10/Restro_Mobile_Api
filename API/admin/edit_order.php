<?php
// api/admin/edit_order.php
// Protected POST endpoint: UPDATE or DELETE existing order + stock adjustment + FULL AUDIT LOG
// Allows staff, admin, superadmin roles

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

// Helper Function: Adjust stock (Add or Subtract)
function adjustStock($conn, $restaurant_id, $stock_name, $delta) {
    if ($delta == 0) return;

    $check = $conn->prepare("
        SELECT quantity FROM stock_inventory 
        WHERE restaurant_id = ? AND stock_name = ?
        FOR UPDATE
    ");
    $check->bind_param("is", $restaurant_id, $stock_name);
    $check->execute();
    $res = $check->get_result();

    if ($row = $res->fetch_assoc()) {
        $current = (int)$row['quantity'];
        if ($delta < 0 && $current < abs($delta)) {
            throw new Exception("Insufficient stock for $stock_name");
        }

        $upd = $conn->prepare("
            UPDATE stock_inventory 
            SET quantity = quantity + ? 
            WHERE restaurant_id = ? AND stock_name = ?
        ");
        $upd->bind_param("iis", $delta, $restaurant_id, $stock_name);
        $upd->execute();
        $upd->close();
    }
    $check->close();
}

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

try {
    // JWT Validation
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token required']);
        exit;
    }

    $token = $matches[1];
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $userData = $decoded->data ?? null;

    if (!$userData || !isset($userData->role)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid token']);
        exit;
    }

    $allowed_roles = ['staff', 'admin', 'superadmin'];
    if (!in_array($userData->role, $allowed_roles)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    $restaurant_id = (int)($userData->restaurant_id ?? 0);
    $changed_by    = (int)($userData->user_id ?? 0);

    if ($restaurant_id <= 0 || $changed_by <= 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid context']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $order_id         = (int)($input['order_id'] ?? 0);
    $action           = strtolower(trim($input['action'] ?? 'update'));
    $table_identifier = trim($input['table_identifier'] ?? '');
    $items            = $input['items'] ?? [];

    if ($order_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Order ID is required']);
        exit;
    }

    // Fetch current order data for audit logging
    $stmt = $conn->prepare("
        SELECT table_number, items, total_amount 
        FROM orders 
        WHERE id = ? AND restaurant_id = ?
    ");
    $stmt->bind_param("ii", $order_id, $restaurant_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $current_order = $result->fetch_assoc();
    $current_items = json_decode($current_order['items'], true) ?? [];

    // Prepare old data for audit
    $old_data = json_encode([
        'table_number' => $current_order['table_number'],
        'items'        => $current_items,
        'total_amount' => (float)$current_order['total_amount']
    ]);

    $change_time = nepali_date_time();
    $remark = ($action === 'delete') ? 'Order deleted by admin' : 'Order updated by admin';

    $conn->begin_transaction();

    try {
        // === 1. INSERT AUDIT LOG INTO old_order ===
        $audit = $conn->prepare("
            INSERT INTO old_order 
            (order_id, restaurant_id, changed_by, change_time, old_data, remarks) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $audit->bind_param("iiisss", $order_id, $restaurant_id, $changed_by, $change_time, $old_data, $remark);
        $audit->execute();
        $audit->close();

        if ($action === 'delete') {
            // Delete order
            $del = $conn->prepare("DELETE FROM orders WHERE id = ? AND restaurant_id = ?");
            $del->bind_param("ii", $order_id, $restaurant_id);
            $del->execute();
            $del->close();

            // Return stock
            foreach ($current_items as $ci) {
                $name = $ci['item_name'] ?? '';
                $qty  = (int)($ci['quantity'] ?? 0);
                if ($qty > 0 && $name) {
                    adjustStock($conn, $restaurant_id, $name, $qty);
                }
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Order deleted successfully']);
            exit;
        }

        // === UPDATE LOGIC ===
        if (empty($table_identifier) || empty($items)) {
            throw new Exception('Table identifier and items are required');
        }

        // Validate menu items and calculate new total
        $item_ids = array_column($items, 'item_id');
        $menu_data = [];
        if (!empty($item_ids)) {
            $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
            $m_stmt = $conn->prepare("SELECT id, item_name, price FROM menu_items WHERE restaurant_id = ? AND id IN ($placeholders)");
            $types = 'i' . str_repeat('i', count($item_ids));
            $m_stmt->bind_param($types, $restaurant_id, ...$item_ids);
            $m_stmt->execute();
            $m_res = $m_stmt->get_result();
            while ($row = $m_res->fetch_assoc()) {
                $menu_data[$row['id']] = $row;
            }
            $m_stmt->close();
        }

        $new_items = [];
        $new_total = 0.0;

        foreach ($items as $it) {
            $id = (int)($it['item_id'] ?? 0);
            $qty = max(1, (int)($it['quantity'] ?? 1));
            $notes = trim($it['notes'] ?? '');

            if (!isset($menu_data[$id])) continue;

            $price = $menu_data[$id]['price'];
            $new_items[] = ['item_id' => $id, 'quantity' => $qty, 'notes' => $notes];
            $new_total += $price * $qty;
        }

        if (empty($new_items)) {
            throw new Exception('No valid items found');
        }

        // Calculate stock delta
        $stock_deltas = [];
        foreach ($new_items as $ni) {
            $name = $menu_data[$ni['item_id']]['item_name'];
            $new_qty = $ni['quantity'];

            $old_qty = 0;
            foreach ($current_items as $ci) {
                if (($ci['item_id'] ?? 0) == $ni['item_id']) {
                    $old_qty = (int)($ci['quantity'] ?? 0);
                    break;
                }
            }
            $delta = $new_qty - $old_qty;
            if ($delta != 0) {
                $stock_deltas[$name] = $delta;
            }
        }

        // Apply stock changes
        foreach ($stock_deltas as $name => $delta) {
            adjustStock($conn, $restaurant_id, $name, $delta);
        }

        // Update main order
        $items_json = json_encode($new_items);
        $upd = $conn->prepare("
            UPDATE orders 
            SET table_number = ?, items = ?, total_amount = ?
            WHERE id = ? AND restaurant_id = ?
        ");
        $upd->bind_param("ssdii", $table_identifier, $items_json, $new_total, $order_id, $restaurant_id);
        $upd->execute();
        $upd->close();

        $conn->commit();

        echo json_encode([
            'success'     => true,
            'message'     => 'Order updated successfully',
            'order_id'    => $order_id,
            'new_total'   => $new_total
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

exit;