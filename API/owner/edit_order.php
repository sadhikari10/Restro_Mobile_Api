<?php
// api/owner/edit_order.php
// POST - Update or delete order for superadmin

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Authorization');

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

// ─── Robust token extraction ───────────────────────────────────────
$token = null;
$headers = getallheaders();

if (isset($headers['X-Authorization'])) {
    $token = $headers['X-Authorization'];
} elseif (isset($headers['x-authorization'])) {
    $token = $headers['x-authorization'];
} elseif (isset($headers['Authorization'])) {
    $token = $headers['Authorization'];
} elseif (isset($headers['authorization'])) {
    $token = $headers['authorization'];
}

if (!$token && isset($_SERVER['HTTP_X_AUTHORIZATION'])) {
    $token = $_SERVER['HTTP_X_AUTHORIZATION'];
} elseif (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $token = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (!$token && isset($_SERVER['REDIRECT_HTTP_X_AUTHORIZATION'])) {
    $token = $_SERVER['REDIRECT_HTTP_X_AUTHORIZATION'];
} elseif (!$token && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $token = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

if (!$token) {
    foreach ($headers as $k => $v) {
        $lowerK = strtolower($k);
        if ($lowerK === 'x-authorization' || $lowerK === 'authorization') {
            $token = $v;
            break;
        }
    }
}

if ($token && preg_match('/Bearer\s+(.+)/i', $token, $matches)) {
    $token = trim($matches[1]);
    error_log("EDIT_ORDER - Token extracted: " . substr($token, 0, 20) . "...");
} else {
    $token = null;
    error_log("EDIT_ORDER - No valid Bearer token found");
}

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication token required']);
    exit;
}

// ─── Decode & validate ─────────────────────────────────────────────
try {
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $user = (array)$decoded->data;

    if (!isset($user['role']) || $user['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Superadmin access only']);
        exit;
    }

    $user_id = (int)$user['user_id'];

    // ─── Input ─────────────────────────────────────────────────────
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $restaurant_id = (int)($input['restaurant_id'] ?? 0);
    $order_id = (int)($input['order_id'] ?? 0);
    $action = trim($input['action'] ?? '');
    $table_identifier = trim($input['table_identifier'] ?? '');
    $items = $input['items'] ?? [];

    if ($restaurant_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'restaurant_id required']);
        exit;
    }
    if ($order_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'order_id required']);
        exit;
    }
    if (!in_array($action, ['update', 'delete'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'action must be "update" or "delete"']);
        exit;
    }

    // ─── Verify ownership ──────────────────────────────────────────
    $check = $conn->prepare("SELECT chain_id FROM restaurants WHERE id = ?");
    $check->bind_param("i", $restaurant_id);
    $check->execute();
    $res = $check->get_result();
    if ($res->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid restaurant']);
        exit;
    }
    $chain_id = (int)$res->fetch_assoc()['chain_id'];
    $check->close();

    $owner_check = $conn->prepare("
        SELECT 1 FROM users 
        WHERE id = ? AND chain_id = ? AND role = 'superadmin'
        LIMIT 1
    ");
    $owner_check->bind_param("ii", $user_id, $chain_id);
    $owner_check->execute();
    if ($owner_check->get_result()->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized restaurant']);
        exit;
    }
    $owner_check->close();

    // ─── Fetch current order ───────────────────────────────────────
    $stmt = $conn->prepare("
        SELECT items, total_amount, table_number, status
        FROM orders
        WHERE id = ? AND restaurant_id = ?
    ");
    $stmt->bind_param("ii", $order_id, $restaurant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current = $result->fetch_assoc();
    $stmt->close();

    if (!$current) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    if (!in_array($current['status'], ['preparing', 'pending'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Cannot modify order in this status']);
        exit;
    }

    $current_items = json_decode($current['items'], true) ?? [];

    // ─── Audit log ──────────────────────────────────────────────────
    $old_data = json_encode([
        'items'        => $current_items,
        'total_amount' => $current['total_amount'],
        'table_number' => $current['table_number']
    ]);

    $time = nepali_date_time();
    $remark = ($action === 'delete') ? 'Order deleted by superadmin' : 'Order updated by superadmin';

    $audit = $conn->prepare("
        INSERT INTO old_order
        (order_id, restaurant_id, changed_by, change_time, old_data, remarks)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $audit->bind_param("iiisss", $order_id, $restaurant_id, $user_id, $time, $old_data, $remark);
    $audit->execute();
    $audit->close();

    $conn->begin_transaction();

    try {
        if ($action === 'delete') {
            // Restore stock
            foreach ($current_items as $ci) {
                $item_id = (int)$ci['item_id'];
                $qty = (int)$ci['quantity'];

                $name_stmt = $conn->prepare("SELECT item_name FROM menu_items WHERE id = ? AND restaurant_id = ?");
                $name_stmt->bind_param("ii", $item_id, $restaurant_id);
                $name_stmt->execute();
                $name_res = $name_stmt->get_result();
                $name_row = $name_res->fetch_assoc();
                $name_stmt->close();

                $stock_name = $name_row['item_name'] ?? null;
                if (!$stock_name) continue;

                $check = $conn->prepare("
                    SELECT quantity FROM stock_inventory 
                    WHERE restaurant_id = ? AND stock_name = ?
                    FOR UPDATE
                ");
                $check->bind_param("is", $restaurant_id, $stock_name);
                $check->execute();
                $stock_res = $check->get_result();

                if ($stock_res->num_rows > 0) {
                    $restore = $conn->prepare("
                        UPDATE stock_inventory 
                        SET quantity = quantity + ? 
                        WHERE restaurant_id = ? AND stock_name = ?
                    ");
                    $restore->bind_param("iis", $qty, $restaurant_id, $stock_name);
                    $restore->execute();
                    $restore->close();
                }
                $check->close();
            }

            // Delete order
            $del = $conn->prepare("DELETE FROM orders WHERE id = ? AND restaurant_id = ?");
            $del->bind_param("ii", $order_id, $restaurant_id);
            $del->execute();
            $del->close();

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Order deleted successfully']);
            exit;
        }

        // ─── UPDATE ────────────────────────────────────────────────────
        if (empty($table_identifier)) {
            throw new Exception('Table identifier required');
        }
        if (empty($items) || !is_array($items)) {
            throw new Exception('Items required');
        }

        // Validate items
        $item_ids = array_column($items, 'item_id');
        if (empty($item_ids)) {
            throw new Exception('No items provided');
        }

        $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
        $stmt = $conn->prepare("
            SELECT id, item_name, price FROM menu_items 
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
                'name' => $row['item_name'],
                'price' => (float)$row['price']
            ];
        }
        $stmt->close();

        if (count($menu_data) !== count(array_unique($item_ids))) {
            throw new Exception('One or more items invalid or unavailable');
        }

        // Prepare new items & total
        $new_order_items = [];
        $new_total = 0.0;

        foreach ($items as $it) {
            $item_id = (int)($it['item_id'] ?? 0);
            $qty = max(1, (int)($it['quantity'] ?? 1));
            $notes = trim($it['notes'] ?? '');

            if (!isset($menu_data[$item_id])) continue;

            $price = $menu_data[$item_id]['price'];
            $new_total += $price * $qty;

            $new_order_items[] = [
                'item_id' => $item_id,
                'quantity' => $qty,
                'notes' => $notes
            ];
        }

        if (empty($new_order_items)) {
            throw new Exception('No valid items in update');
        }

        // ─── STOCK ADJUSTMENT ──────────────────────────────────────────
        $old_map = [];
        foreach ($current_items as $ci) {
            $old_map[(int)$ci['item_id']] = (int)$ci['quantity'];
        }

        $new_map = [];
        foreach ($new_order_items as $ni) {
            $new_map[$ni['item_id']] = $ni['quantity'];
        }

        $all_ids = array_unique(array_merge(array_keys($old_map), array_keys($new_map)));

        foreach ($all_ids as $item_id) {
            $old_qty = $old_map[$item_id] ?? 0;
            $new_qty = $new_map[$item_id] ?? 0;

            if ($old_qty === $new_qty) continue;

            $name_stmt = $conn->prepare("SELECT item_name FROM menu_items WHERE id = ? AND restaurant_id = ?");
            $name_stmt->bind_param("ii", $item_id, $restaurant_id);
            $name_stmt->execute();
            $name_res = $name_stmt->get_result();
            $name_row = $name_res->fetch_assoc();
            $name_stmt->close();

            $stock_name = $name_row['item_name'] ?? null;
            if (!$stock_name) continue;

            $check = $conn->prepare("
                SELECT quantity FROM stock_inventory 
                WHERE restaurant_id = ? AND stock_name = ?
                FOR UPDATE
            ");
            $check->bind_param("is", $restaurant_id, $stock_name);
            $check->execute();
            $stock_res = $check->get_result();

            if ($stock_res->num_rows > 0) {
                $row = $stock_res->fetch_assoc();
                $current_stock = (int)$row['quantity'];

                $difference = abs($new_qty - $old_qty);

                if ($new_qty > $old_qty) {
                    // Increasing → consume more stock
                    if ($current_stock < $difference) {
                        throw new Exception("Insufficient stock for '{$stock_name}': need {$difference} more, have {$current_stock}");
                    }
                    $adjust_stmt = $conn->prepare("
                        UPDATE stock_inventory 
                        SET quantity = quantity - ? 
                        WHERE restaurant_id = ? AND stock_name = ?
                    ");
                    $adjust_stmt->bind_param("iis", $difference, $restaurant_id, $stock_name);
                } else {
                    // Decreasing → restore stock
                    $adjust_stmt = $conn->prepare("
                        UPDATE stock_inventory 
                        SET quantity = quantity + ? 
                        WHERE restaurant_id = ? AND stock_name = ?
                    ");
                    $adjust_stmt->bind_param("iis", $difference, $restaurant_id, $stock_name);
                }

                $adjust_stmt->execute();
                $adjust_stmt->close();
            }
            $check->close();
        }

        // Update order
        $items_json = json_encode($new_order_items);

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
            'success' => true,
            'message' => 'Order updated successfully',
            'new_total' => $new_total
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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