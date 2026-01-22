<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

$restaurant_id = $_SESSION['restaurant_id'];
$user_id = $_SESSION['user_id'] ?? 0;

/* ==================== AJAX: Restore stock on uncheck ==================== */
if (isset($_POST['restore_stock']) && $_POST['restore_stock'] == '1') {
    header('Content-Type: application/json');
    $item_name = trim($_POST['item_name'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);
    $rest_id = (int)($_POST['restaurant_id'] ?? 0);

    $response = ['success' => false];

    if ($item_name && $quantity > 0 && $rest_id === $restaurant_id) {
        $check = $conn->prepare("SELECT id FROM stock_inventory WHERE restaurant_id = ? AND stock_name = ?");
        $check->bind_param("is", $restaurant_id, $item_name);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $restore = $conn->prepare("UPDATE stock_inventory SET quantity = quantity + ? WHERE restaurant_id = ? AND stock_name = ?");
            $restore->bind_param("iis", $quantity, $restaurant_id, $item_name);
            $restore->execute();
            $restore->close();
            $response['success'] = true;
        }
        $check->close();
    }
    echo json_encode($response);
    exit;
}

/* ==================== MAIN POST HANDLER (UPDATE / DELETE) ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && !isset($_POST['restore_stock'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    $order_id = (int)$_POST['order_id'];

    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND restaurant_id = ?");
    $stmt->bind_param("ii", $order_id, $restaurant_id);
    $stmt->execute();
    $order_result = $stmt->get_result();
    $order = $order_result->fetch_assoc();
    $stmt->close();

    if (!$order) {
        $response['message'] = 'Order not found.';
        echo json_encode($response);
        exit;
    }

    $current_items = json_decode($order['items'], true) ?? [];
    $current_qty = [];
    foreach ($current_items as $ci) {
        $current_qty[$ci['item_id']] = $ci['quantity'];
    }

    try {
        if (!empty($_POST['delete_order']) && $_POST['delete_order'] == '1') {
            $conn->begin_transaction();

            foreach ($current_items as $item) {
                $item_id = $item['item_id'];
                $qty = $item['quantity'];

                $name_stmt = $conn->prepare("SELECT item_name FROM menu_items WHERE id = ? AND restaurant_id = ?");
                $name_stmt->bind_param("ii", $item_id, $restaurant_id);
                $name_stmt->execute();
                $name_row = $name_stmt->get_result()->fetch_assoc();
                $name_stmt->close();
                $item_name = $name_row['item_name'] ?? 'Unknown';

                $check = $conn->prepare("SELECT id FROM stock_inventory WHERE restaurant_id = ? AND stock_name = ?");
                $check->bind_param("is", $restaurant_id, $item_name);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if ($exists) {
                    $restore = $conn->prepare("UPDATE stock_inventory SET quantity = quantity + ? WHERE restaurant_id = ? AND stock_name = ?");
                    $restore->bind_param("iis", $qty, $restaurant_id, $item_name);
                    $restore->execute();
                    $restore->close();
                }
            }

            $old_data = [
                'items' => $current_items,
                'total_amount' => $order['total_amount'],
                'table_number' => $order['table_number'],
                'order_by' => $order['order_by'] ?? null
            ];
            $remarks = "Order deleted by admin";
            $change_time = nepali_date_time();

            $archive = $conn->prepare("INSERT INTO old_order (order_id, restaurant_id, changed_by, change_time, old_data, remarks) VALUES (?, ?, ?, ?, ?, ?)");
            $json_old = json_encode($old_data);
            $archive->bind_param("iiisss", $order_id, $restaurant_id, $user_id, $change_time, $json_old, $remarks);
            $archive->execute();
            $archive->close();

            $del = $conn->prepare("DELETE FROM orders WHERE id = ? AND restaurant_id = ?");
            $del->bind_param("ii", $order_id, $restaurant_id);
            $del->execute();
            $del->close();

            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'Order deleted successfully!';
        } else {
            $selected_items = $_POST['selected_items'] ?? [];
            $quantities = $_POST['quantities'] ?? [];
            $notes_arr = $_POST['notes'] ?? [];
            $table_identifier = trim($_POST['table_identifier'] ?? '');

            if (empty($selected_items)) {
                throw new Exception('Please select at least one item.');
            }
            if (empty($table_identifier)) {
                throw new Exception('Please enter Table / Name / Phone.');
            }

            // Fetch selected items (allow previously selected even if disabled)
            $placeholders = str_repeat('?,', count($selected_items) - 1) . '?';
            $val_stmt = $conn->prepare("SELECT id, item_name, price FROM menu_items WHERE id IN ($placeholders) AND restaurant_id = ?");
            $types = str_repeat('i', count($selected_items)) . 'i';
            $params = array_merge($selected_items, [$restaurant_id]);
            $refs = [];
            foreach ($params as $k => $v) $refs[] = &$params[$k];
            call_user_func_array([$val_stmt, 'bind_param'], array_merge([$types], $refs));
            $val_stmt->execute();
            $val_res = $val_stmt->get_result();
            $valid_items = [];
            while ($row = $val_res->fetch_assoc()) {
                $valid_items[$row['id']] = $row;
            }
            $val_stmt->close();

            if (empty($valid_items)) {
                throw new Exception('Selected items are no longer valid.');
            }

            $new_items = [];
            $total = 0;

            $conn->begin_transaction();

            foreach ($selected_items as $item_id_str) {
                $item_id = (int)$item_id_str;
                if (!isset($valid_items[$item_id])) continue;

                $qty = max(1, (int)($quantities[$item_id_str] ?? 1));
                $note = trim($notes_arr[$item_id_str] ?? '');

                $item = $valid_items[$item_id];
                $old_qty = $current_qty[$item_id] ?? 0;
                $diff = $qty - $old_qty;

                if ($diff != 0) {
                    $exists_check = $conn->prepare("SELECT id FROM stock_inventory WHERE restaurant_id = ? AND stock_name = ?");
                    $exists_check->bind_param("is", $restaurant_id, $item['item_name']);
                    $exists_check->execute();
                    $exists = $exists_check->get_result()->num_rows > 0;
                    $exists_check->close();

                    if ($exists) {
                        if ($diff > 0) {
                            $check = $conn->prepare("SELECT quantity FROM stock_inventory WHERE restaurant_id = ? AND stock_name = ? FOR UPDATE");
                            $check->bind_param("is", $restaurant_id, $item['item_name']);
                            $check->execute();
                            $stock_row = $check->get_result()->fetch_assoc();
                            $check->close();
                            if ($stock_row['quantity'] < $diff) {
                                throw new Exception("Not enough stock for <strong>{$item['item_name']}</strong>.");
                            }
                        }

                        $op = $diff > 0 ? '-' : '+';
                        $abs_diff = abs($diff);
                        $adjust = $conn->prepare("UPDATE stock_inventory SET quantity = quantity $op ? WHERE restaurant_id = ? AND stock_name = ?");
                        $adjust->bind_param("iis", $abs_diff, $restaurant_id, $item['item_name']);
                        $adjust->execute();
                        $adjust->close();
                    }
                }

                $total += $qty * $item['price'];
                $new_items[] = ['item_id' => $item_id, 'quantity' => $qty, 'notes' => $note];
            }

            $old_data = [
                'items' => $current_items,
                'total_amount' => $order['total_amount'],
                'table_number' => $order['table_number']
            ];
            $remarks = "Order edited by admin";
            $change_time = nepali_date_time();

            $archive = $conn->prepare("INSERT INTO old_order (order_id, restaurant_id, changed_by, change_time, old_data, remarks) VALUES (?, ?, ?, ?, ?, ?)");
            $json_old = json_encode($old_data);
            $archive->bind_param("iiisss", $order_id, $restaurant_id, $user_id, $change_time, $json_old, $remarks);
            $archive->execute();
            $archive->close();

            $items_json = json_encode($new_items);
            $updated_at_bs = nepali_date_time();

            $upd = $conn->prepare("UPDATE orders SET table_number = ?, items = ?, total_amount = ?, updated_at = ? WHERE id = ? AND restaurant_id = ?");
            $upd->bind_param("ssdssi", $table_identifier, $items_json, $total, $updated_at_bs, $order_id, $restaurant_id);
            $upd->execute();
            $upd->close();

            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'Order updated successfully!';
        }
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
    exit;
}
/* ==================== FETCH DATA ==================== */
$stmt = $conn->prepare("SELECT o.*, u.username AS ordered_by_name 
                        FROM orders o 
                        LEFT JOIN users u ON o.order_by = u.id 
                        WHERE o.restaurant_id = ? 
                          AND o.status = 'preparing' 
                        ORDER BY o.id DESC");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$orders_result = $stmt->get_result();
$orders = $orders_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT id, item_name, price, description, category FROM menu_items WHERE restaurant_id = ? AND status = 'available'");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$menu_result = $stmt->get_result();
$menu_items = $menu_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$categories = array_unique(array_filter(array_column($menu_items, 'category')));
sort($categories);

$stock = [];
$stock_stmt = $conn->prepare("SELECT stock_name, quantity FROM stock_inventory WHERE restaurant_id = ?");
$stock_stmt->bind_param("i", $restaurant_id);
$stock_stmt->execute();
$res = $stock_stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $stock[$row['stock_name']] = $row['quantity'];
}
$stock_stmt->close();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Orders - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="admin.css" rel="stylesheet">
    <style>
        .item-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); transition: all 0.3s ease; }
        .item-details { display: none; margin-top: 12px; }
        .stock-available { color: #17a2b8; font-weight: 500; }
        #centerToast {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1050;
            min-width: 300px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            display: none;
        }
        .order-card-wrapper { margin-bottom: 3rem; }
        .order-info-row { background-color: #f8f9fa; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
    </style>
</head>
<body class="bg-light">

<div class="container py-4 py-md-5">
    <h3 class="mb-4 text-center text-success fw-bold">Edit Orders</h3>

    <div class="text-center mb-4">
        <a href="dashboard.php" class="btn btn-secondary px-5">Back to Dashboard</a>
    </div>

    <div id="statusMessage" class="alert text-center p-4 rounded shadow-sm mb-4" style="display:none;">
        <strong id="statusText"></strong>
    </div>

    <!-- One order per block -->
    <?php foreach ($orders as $order): ?>
    <div class="row order-card-wrapper">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0">Order #<?= $order['id'] ?> - <?= htmlspecialchars($order['table_number']) ?></h6>
                    <small>By: <?= htmlspecialchars($order['ordered_by_name'] ?? 'Unknown') ?> | <?= $order['created_at'] ?></small>
                </div>
                <div class="card-body">
                    <!-- Row 1: Total -->
                    <div class="row order-info-row">
                        <div class="col-12">
                            <strong>Total Amount:</strong> Rs <?= number_format($order['total_amount'], 2) ?>
                        </div>
                    </div>

                    <!-- Row 2: Status -->
                    <div class="row order-info-row">
                        <div class="col-12">
                            <strong>Status:</strong> <span class="badge bg-warning"><?= ucfirst($order['status']) ?></span>
                        </div>
                    </div>

                    <!-- Row 3: Edit Items -->
                    <div class="row">
                        <div class="col-12">
                            <form method="POST" id="editForm_<?= $order['id'] ?>">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <input type="text" name="table_identifier" class="form-control mb-3" value="<?= htmlspecialchars($order['table_number']) ?>" required>

                                <div class="mb-3">
                                    <input type="text" class="form-control searchInput" data-order="<?= $order['id'] ?>" placeholder="Search items...">
                                </div>

                                <?php 
                                $order_items = json_decode($order['items'], true) ?? [];
                                $order_categories = array_unique(array_merge(array_column($order_items, 'category'), $categories));
                                sort($order_categories);
                                ?>
                                <ul class="nav nav-tabs mb-3" id="catTabs_<?= $order['id'] ?>">
                                    <?php foreach ($order_categories as $idx => $cat): ?>
                                    <li class="nav-item">
                                        <button class="nav-link <?= $idx === 0 ? 'active' : '' ?>" type="button" data-order="<?= $order['id'] ?>" data-category="<?= htmlspecialchars($cat ?: 'Uncategorized') ?>">
                                            <?= htmlspecialchars($cat ?: 'Uncategorized') ?>
                                        </button>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>

                                <div class="row g-3" id="menuGrid_<?= $order['id'] ?>"></div>

                                <div class="text-center mt-4">
                                    <button type="button" class="btn btn-primary me-2" id="updateBtn_<?= $order['id'] ?>">
                                        Update Order
                                    </button>
                                    <button type="button" class="btn btn-danger" id="deleteBtn_<?= $order['id'] ?>">
                                        Delete Order
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Centered Toast -->
<div id="centerToast" class="card border-0">
    <div class="card-body text-center">
        <i class="bi bi-exclamation-triangle fs-1 text-danger mb-3"></i>
        <p id="toastMessage" class="fw-bold fs-5"></p>
    </div>
</div>

<!-- Confirm Update Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Confirm Update</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="orderSummary"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmUpdateBtn">Confirm Update</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Delete Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p>Are you sure you want to delete this order?</p>
                <p class="text-danger fw-bold">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Yes, Delete</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const menuItems = <?= json_encode($menu_items) ?>;
    const stock = <?= json_encode($stock) ?>;

    let orderState = {};
    let currentOrderId = null;

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function renderCategory(orderId, category) {
        const grid = document.getElementById('menuGrid_' + orderId);
        grid.innerHTML = '';

        const filtered = menuItems.filter(item => (item.category || '').trim() === category);
        if (filtered.length === 0) {
            grid.innerHTML = '<div class="col-12 text-center py-5 text-muted">No items in this category</div>';
            return;
        }

        filtered.forEach(item => {
            const state = (orderState[orderId] && orderState[orderId][item.id]) || { qty: 1, notes: '' };
            const checked = !!orderState[orderId]?.[item.id];
            const hasStock = stock.hasOwnProperty(item.item_name);
            const stockDisplay = hasStock ? stock[item.item_name] : null;

            const div = document.createElement('div');
            div.className = 'col-6 col-md-4 col-lg-3 mb-4';
            div.innerHTML = `
                <div class="card h-100 border-0 shadow-sm item-card">
                    <div class="card-body p-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="${item.id}" id="item_${orderId}_${item.id}" ${checked ? 'checked' : ''}>
                            <label class="form-check-label fw-bold small d-block" for="item_${orderId}_${item.id}">
                                ${escapeHtml(item.item_name)}
                                <span class="badge bg-info ms-1 small">${escapeHtml(item.category)}</span>
                                <span class="text-success float-end small">Rs ${parseFloat(item.price).toFixed(2)}</span>
                            </label>
                            ${item.description ? `<small class="d-block text-muted mt-1 small">${escapeHtml(item.description)}</small>` : ''}
                            <div class="mt-2 small">
                                <span class="badge bg-success">Available</span>
                                ${hasStock ? `<span class="ms-2 stock-available">Stock: ${stockDisplay}</span>` : ''}
                            </div>
                        </div>
                        <div id="details_${orderId}_${item.id}" style="display:${checked ? 'block' : 'none'}; margin-top:12px;">
                            <div class="input-group input-group-sm mb-2">
                                <span class="input-group-text">Qty</span>
                                <input type="number" class="form-control qty-input" value="${state.qty}" min="1">
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">Notes</span>
                                <input type="text" class="form-control notes-input" value="${escapeHtml(state.notes)}" placeholder="e.g. less spicy">
                            </div>
                        </div>
                    </div>
                </div>
            `;
            grid.appendChild(div);
        });
    }

    // Initialize orderState with existing items
    <?php foreach ($orders as $order): ?>
    orderState[<?= $order['id'] ?>] = {};
    <?php 
    $order_items = json_decode($order['items'], true) ?? [];
    foreach ($order_items as $i): 
    ?>
    orderState[<?= $order['id'] ?>][<?= $i['item_id'] ?>] = { qty: <?= $i['quantity'] ?>, notes: <?= json_encode($i['notes'] ?? '') ?> };
    <?php endforeach; ?>
    <?php endforeach; ?>

    // Checkbox change - with stock restore on uncheck
    document.addEventListener('change', function(e) {
        if (e.target.type === 'checkbox' && e.target.id.startsWith('item_')) {
            const match = e.target.id.match(/item_(\d+)_(\d+)/);
            if (!match) return;
            const orderId = match[1];
            const itemId = match[2];
            const details = document.getElementById('details_' + orderId + '_' + itemId);

            if (!orderState[orderId]) orderState[orderId] = {};

            if (e.target.checked) {
                details.style.display = 'block';
                const qtyInput = details.querySelector('.qty-input');
                const notesInput = details.querySelector('.notes-input');
                orderState[orderId][itemId] = {
                    qty: parseInt(qtyInput.value) || 1,
                    notes: notesInput.value.trim()
                };
            } else {
                details.style.display = 'none';
                const qtyToRestore = parseInt(details.querySelector('.qty-input').value) || 1;

                const item = menuItems.find(i => i.id == itemId);
                if (item && stock.hasOwnProperty(item.item_name)) {
                    const formData = new FormData();
                    formData.append('restore_stock', '1');
                    formData.append('item_name', item.item_name);
                    formData.append('quantity', qtyToRestore);
                    formData.append('restaurant_id', <?= $restaurant_id ?>);

                    fetch('', {
                        method: 'POST',
                        body: formData
                    }).catch(err => console.error('Stock restore failed:', err));
                }

                details.querySelector('.qty-input').value = 1;
                details.querySelector('.notes-input').value = '';
                delete orderState[orderId][itemId];
            }
        }
    });

    // Input changes
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('qty-input') || e.target.classList.contains('notes-input')) {
            const card = e.target.closest('.item-card');
            if (!card) return;
            const checkbox = card.querySelector('input[type="checkbox"]');
            if (!checkbox || !checkbox.checked) return;

            const match = checkbox.id.match(/item_(\d+)_(\d+)/);
            if (!match) return;
            const orderId = match[1];
            const itemId = match[2];

            if (!orderState[orderId]) orderState[orderId] = {};

            const qty = parseInt(card.querySelector('.qty-input').value) || 1;
            const notes = card.querySelector('.notes-input').value.trim();
            orderState[orderId][itemId] = { qty, notes };
        }
    });

    // Tab switching
    document.querySelectorAll('[id^="catTabs_"] button').forEach(btn => {
        btn.addEventListener('click', function() {
            const orderId = this.dataset.order;
            document.querySelectorAll(`#catTabs_${orderId} button`).forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            renderCategory(orderId, this.dataset.category);
        });
    });

    // Update button
    document.querySelectorAll('[id^="updateBtn_"]').forEach(btn => {
        btn.addEventListener('click', function() {
            currentOrderId = this.id.split('_')[1];
            const form = document.getElementById('editForm_' + currentOrderId);
            const table = form.querySelector('input[name="table_identifier"]').value.trim();
            const selected = Object.keys(orderState[currentOrderId] || {});

            if (selected.length === 0) {
                showCenterToast('Please select at least one item.');
                return;
            }
            if (!table) {
                showCenterToast('Please enter Table / Name / Phone.');
                return;
            }

            let html = `<p class="fw-bold fs-5 mb-3">Update order for: <span class="text-primary">${escapeHtml(table)}</span></p>`;
            html += '<ul class="list-group list-group-flush">';
            let total = 0;

            selected.forEach(id => {
                const item = menuItems.find(i => i.id == id);
                if (!item) return;
                const { qty, notes } = orderState[currentOrderId][id];
                const sub = item.price * qty;
                total += sub;
                html += `
                    <li class="list-group-item d-flex justify-content-between">
                        <div>
                            <div class="fw-bold">${escapeHtml(item.item_name)} × ${qty}</div>
                            ${notes ? `<small class="text-muted">${escapeHtml(notes)}</small>` : ''}
                        </div>
                        <span class="badge bg-primary rounded-pill">Rs ${sub.toFixed(2)}</span>
                    </li>`;
            });

            html += `</ul><hr><div class="d-flex justify-content-between fs-5">
                <strong>Total Amount:</strong>
                <strong class="text-success">Rs ${total.toFixed(2)}</strong>
            </div>`;

            document.getElementById('orderSummary').innerHTML = html;
            new bootstrap.Modal('#confirmModal').show();
        });
    });

    // Confirm update
    document.getElementById('confirmUpdateBtn').addEventListener('click', () => {
        const form = document.getElementById('editForm_' + currentOrderId);
        const formData = new FormData(form);

        formData.delete('selected_items[]');
        for (let key of formData.keys()) {
            if (key.startsWith('quantities[') || key.startsWith('notes[')) {
                formData.delete(key);
            }
        }

        Object.keys(orderState[currentOrderId] || {}).forEach(id => {
            const { qty, notes } = orderState[currentOrderId][id];
            formData.append('selected_items[]', id);
            formData.append(`quantities[${id}]`, qty);
            formData.append(`notes[${id}]`, notes);
        });

        fetch('edit_orders.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('statusText').textContent = data.message;
            document.getElementById('statusMessage').className = 'alert alert-' + (data.success ? 'success' : 'danger');
            document.getElementById('statusMessage').style.display = 'block';
            if (data.success) {
                 location.reload();
            }
        })
        .catch(() => {
            document.getElementById('statusText').textContent = 'Update failed.';
            document.getElementById('statusMessage').className = 'alert alert-danger';
            document.getElementById('statusMessage').style.display = 'block';
        });
    });

    // Delete
    document.querySelectorAll('[id^="deleteBtn_"]').forEach(btn => {
        btn.addEventListener('click', function() {
            currentOrderId = this.id.split('_')[1];
            new bootstrap.Modal('#deleteConfirmModal').show();
        });
    });

    document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
        const form = document.getElementById('editForm_' + currentOrderId);
        const formData = new FormData(form);
        formData.set('delete_order', '1');

        fetch('edit_orders.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(d => {
                document.getElementById('statusText').textContent = d.message;
                document.getElementById('statusMessage').className = 'alert alert-success';
                document.getElementById('statusMessage').style.display = 'block';
                 location.reload();
            });
    });

    function showCenterToast(message) {
        document.getElementById('toastMessage').textContent = message;
        document.getElementById('centerToast').style.display = 'block';
        setTimeout(() => document.getElementById('centerToast').style.display = 'none', 4000);
    }

    // Initial load
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[id^="catTabs_"] button:first-child').forEach(first => {
            first.classList.add('active');
            const orderId = first.dataset.order;
            renderCategory(orderId, first.dataset.category);
        });
    });
</script>

<?php include '../Common/footer.php'; ?>
</body>
</html>