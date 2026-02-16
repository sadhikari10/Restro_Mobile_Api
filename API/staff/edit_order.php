<?php
// api/staff/edit_order.php
// Protected endpoint: UPDATE or DELETE existing order + handle stock the same way as web version
// Requires valid JWT (staff role)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');           // tighten in production
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

// JWT Secret
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
    // ── JWT validation ───────────────────────────────────────
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token required']);
        exit;
    }

    $token = $matches[1];
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $userData = $decoded->data ?? null;

    if (!$userData || !isset($userData->role) || $userData->role !== 'staff') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Staff access required']);
        exit;
    }

    $restaurant_id = (int)($userData->restaurant_id ?? 0);
    $staff_id      = (int)($userData->user_id ?? 0);

    if ($restaurant_id <= 0 || $staff_id <= 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid context']);
        exit;
    }

    // ── Read payload ─────────────────────────────────────────
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $order_id         = (int)($input['order_id']         ?? 0);
    $action           = trim($input['action']            ?? '');
    $table_identifier = trim($input['table_identifier']  ?? '');
    $items            = $input['items'] ?? [];

    if ($order_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Order ID required']);
        exit;
    }

    if (!in_array($action, ['update', 'delete'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action. Use "update" or "delete"']);
        exit;
    }

    // ── Fetch current order ──────────────────────────────────
    $stmt = $conn->prepare("
        SELECT items, total_amount, table_number, status
        FROM orders
        WHERE id = ? AND restaurant_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $order_id, $restaurant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();
    $stmt->close();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found or not yours']);
        exit;
    }

    if (!in_array($order['status'], ['preparing', 'pending'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Order cannot be modified (status: ' . $order['status'] . ')']);
        exit;
    }

    $current_items = json_decode($order['items'], true) ?? [];

    // ── Audit log (same as web) ──────────────────────────────
    $old_data = json_encode([
        'items'        => $current_items,
        'total_amount' => $order['total_amount'],
        'table_number' => $order['table_number']
    ]);
    $change_time = nepali_date_time();
    $remark = ($action === 'delete') ? 'Order deleted by staff' : 'Order edited by staff';

    $audit = $conn->prepare("
        INSERT INTO old_order 
        (order_id, restaurant_id, changed_by, change_time, old_data, remarks) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $audit->bind_param("iiisss", $order_id, $restaurant_id, $staff_id, $change_time, $old_data, $remark);
    $audit->execute();
    $audit->close();

    $conn->begin_transaction();

    try {
        if ($action === 'delete') {
            // ── Delete: restore stock for tracked items only ─────
            foreach ($current_items as $ci) {
                $item_id = (int)$ci['item_id'];
                $qty     = (int)$ci['quantity'];

                $nameStmt = $conn->prepare("SELECT item_name FROM menu_items WHERE id = ? AND restaurant_id = ?");
                $nameStmt->bind_param("ii", $item_id, $restaurant_id);
                $nameStmt->execute();
                $nameRow = $nameStmt->get_result()->fetch_assoc();
                $nameStmt->close();

                $item_name = $nameRow['item_name'] ?? null;
                if (!$item_name) continue;

                $check = $conn->prepare("
                    SELECT quantity FROM stock_inventory 
                    WHERE restaurant_id = ? AND stock_name = ?
                    FOR UPDATE
                ");
                $check->bind_param("is", $restaurant_id, $item_name);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $restore = $conn->prepare("
                        UPDATE stock_inventory 
                        SET quantity = quantity + ? 
                        WHERE restaurant_id = ? AND stock_name = ?
                    ");
                    $restore->bind_param("iis", $qty, $restaurant_id, $item_name);
                    $restore->execute();
                    $restore->close();
                }
                $check->close();
            }

            $del = $conn->prepare("DELETE FROM orders WHERE id = ? AND restaurant_id = ?");
            $del->bind_param("ii", $order_id, $restaurant_id);
            $del->execute();
            $del->close();

            $conn->commit();

            echo json_encode([
                'success'  => true,
                'message'  => 'Order deleted successfully',
                'order_id' => $order_id
            ]);
            exit;
        }

        // ── Update ───────────────────────────────────────────────
        if (empty($table_identifier)) {
            throw new Exception('Table / Customer / Phone is required');
        }
        if (empty($items) || !is_array($items)) {
            throw new Exception('At least one item is required');
        }

        // Validate items
        $selected_ids = array_column($items, 'item_id');
        $placeholders = str_repeat('?,', count($selected_ids) - 1) . '?';
        $stmt = $conn->prepare("
            SELECT id, item_name, price
            FROM menu_items
            WHERE id IN ($placeholders) AND restaurant_id = ? AND status = 'available'
        ");
        $types = str_repeat('i', count($selected_ids)) . 'i';
        $params = array_merge($selected_ids, [$restaurant_id]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $menu_data = [];
        while ($row = $result->fetch_assoc()) {
            $menu_data[$row['id']] = ['name' => $row['item_name'], 'price' => (float)$row['price']];
        }
        $stmt->close();

        if (count($menu_data) !== count($selected_ids)) {
            throw new Exception('One or more items are invalid or unavailable');
        }

        $new_order_items = [];
        $new_total = 0.0;

        foreach ($items as $it) {
            $item_id  = (int)($it['item_id'] ?? 0);
            $quantity = max(1, (int)($it['quantity'] ?? 1));
            $notes    = trim($it['notes'] ?? '');

            if (!isset($menu_data[$item_id])) continue;

            $price    = $menu_data[$item_id]['price'];
            $subtotal = $price * $quantity;
            $new_total += $subtotal;

            $new_order_items[] = [
                'item_id'   => $item_id,
                'quantity'  => $quantity,
                'notes'     => $notes
            ];
        }

        if (empty($new_order_items)) {
            throw new Exception('No valid items in update');
        }

        // ── Stock adjustment using if-else ───────────────────────
        $id_to_old = [];
        foreach ($current_items as $ci) {
            $id_to_old[(int)$ci['item_id']] = (int)$ci['quantity'];
        }

        $id_to_new = [];
        foreach ($new_order_items as $ni) {
            $id_to_new[$ni['item_id']] = $ni['quantity'];
        }

        $all_item_ids = array_unique(array_merge(array_keys($id_to_old), array_keys($id_to_new)));

        foreach ($all_item_ids as $menu_item_id) {
            $old_qty = $id_to_old[$menu_item_id] ?? 0;
            $new_qty = $id_to_new[$menu_item_id] ?? 0;

            if ($old_qty === $new_qty) {
                continue;
            }

            // Get item name
            $nameStmt = $conn->prepare("SELECT item_name FROM menu_items WHERE id = ? AND restaurant_id = ?");
            $nameStmt->bind_param("ii", $menu_item_id, $restaurant_id);
            $nameStmt->execute();
            $nameRow = $nameStmt->get_result()->fetch_assoc();
            $nameStmt->close();

            $stock_name = $nameRow['item_name'] ?? null;
            if (!$stock_name) continue;

            // Check if this item is tracked in inventory
            $check = $conn->prepare("
                SELECT quantity FROM stock_inventory 
                WHERE restaurant_id = ? AND stock_name = ?
                FOR UPDATE
            ");
            $check->bind_param("is", $restaurant_id, $stock_name);
            $check->execute();
            $stock_res = $check->get_result();

            if ($stock_res->num_rows > 0) {
                $sr = $stock_res->fetch_assoc();
                $current_stock = (int)$sr['quantity'];

                $difference = abs($new_qty - $old_qty);

                if ($new_qty > $old_qty) {
                    // Case 1: Increasing quantity → consume more stock
                    if ($current_stock < $difference) {
                        throw new Exception("Insufficient stock for {$stock_name}: need {$difference} more, have {$current_stock}");
                    }
                    $stmt = $conn->prepare("
                        UPDATE stock_inventory 
                        SET quantity = quantity - ? 
                        WHERE restaurant_id = ? AND stock_name = ?
                    ");
                    $stmt->bind_param("iis", $difference, $restaurant_id, $stock_name);
                } else {
                    // Case 2: Decreasing quantity → restore stock
                    $stmt = $conn->prepare("
                        UPDATE stock_inventory 
                        SET quantity = quantity + ? 
                        WHERE restaurant_id = ? AND stock_name = ?
                    ");
                    $stmt->bind_param("iis", $difference, $restaurant_id, $stock_name);
                }

                $stmt->execute();
                $stmt->close();
            }
            $check->close();
        }

        // ── Update order ─────────────────────────────────────────
        $items_json = json_encode($new_order_items);

        $upd_stmt = $conn->prepare("
            UPDATE orders 
            SET 
                table_number = ?,
                items        = ?,
                total_amount = ?
            WHERE id = ? AND restaurant_id = ?
        ");
        $upd_stmt->bind_param("ssdii", $table_identifier, $items_json, $new_total, $order_id, $restaurant_id);
        $upd_stmt->execute();
        $upd_stmt->close();

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
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage()
        ]);
    }

} catch (Firebase\JWT\ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token expired']);
} catch (Firebase\JWT\SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

exit;