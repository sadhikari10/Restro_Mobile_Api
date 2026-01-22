<?php
// SuperAdmin/edit_orders.php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin' || empty($_SESSION['current_restaurant_id'])) {
    header('Location: ../login.php');
    exit;
}

$restaurant_id = $_SESSION['current_restaurant_id'];
$user_id = $_SESSION['user_id'] ?? 0;

$error_msg = '';
$success_msg = '';

/* ==================== RESTAURANT NAME ==================== */
$stmt = $conn->prepare("SELECT name FROM restaurants WHERE id = ?");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$res = $stmt->get_result();
$restaurant = $res->fetch_assoc();
$stmt->close();
$restaurant_name = $restaurant['name'] ?? 'Unknown Branch';

/* ==================== HANDLE POST (UPDATE / DELETE) ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    $order_id = (int)$_POST['order_id'];

    $fetch = $conn->prepare("SELECT * FROM orders WHERE id = ? AND restaurant_id = ?");
    $fetch->bind_param("ii", $order_id, $restaurant_id);
    $fetch->execute();
    $order = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    if (!$order) {
        $error_msg = "Order not found.";
    } else {
        $cur_items = json_decode($order['items'], true) ?? [];
        $current_qty = [];
        foreach ($cur_items as $item) {
            $current_qty[$item['item_id']] = $item['quantity'];
        }

        if (isset($_POST['delete_order']) && $_POST['delete_order'] === '1') {
            // === DELETE ORDER ===
            try {
                $conn->begin_transaction();

                $old_data = [
                    'items' => $cur_items,
                    'total_amount' => $order['total_amount'],
                    'table_number' => $order['table_number'],
                    'order_by' => $order['order_by'] ?? null
                ];
                $remarks = "Order deleted by superadmin";
                $change_time = nepali_date_time();

                $archive = $conn->prepare("INSERT INTO old_order (order_id, restaurant_id, changed_by, change_time, old_data, remarks) VALUES (?, ?, ?, ?, ?, ?)");
                $json_old = json_encode($old_data);
                $archive->bind_param("iiisss", $order_id, $restaurant_id, $user_id, $change_time, $json_old, $remarks);
                $archive->execute();
                $archive->close();

                // Restore stock
                foreach ($cur_items as $item) {
                    $item_id = $item['item_id'];
                    $qty = $item['quantity'];

                    $name_stmt = $conn->prepare("SELECT item_name FROM menu_items WHERE id = ? AND restaurant_id = ?");
                    $name_stmt->bind_param("ii", $item_id, $restaurant_id);
                    $name_stmt->execute();
                    $row = $name_stmt->get_result()->fetch_assoc();
                    $name_stmt->close();
                    $item_name = $row['item_name'] ?? null;

                    if ($item_name) {
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
                }

                $del = $conn->prepare("DELETE FROM orders WHERE id = ? AND restaurant_id = ?");
                $del->bind_param("ii", $order_id, $restaurant_id);
                $del->execute();
                $del->close();

                $conn->commit();
                $success_msg = "Order deleted successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Delete failed.";
            }
        } else {
            // === UPDATE ORDER ===
            $selected_items = $_POST['selected_items'] ?? [];
            $quantities = $_POST['quantities'] ?? [];
            $notes_arr = $_POST['notes'] ?? [];
            $table_identifier = trim($_POST['table_identifier'] ?? '');

            if (empty($selected_items)) {
                $error_msg = "Please select at least one item.";
            } elseif (empty($table_identifier)) {
                $error_msg = "Please enter Table / Name / Phone.";
            } else {
                $placeholders = str_repeat('?,', count($selected_items) - 1) . '?';
                $stmt = $conn->prepare("SELECT id, item_name, price FROM menu_items WHERE id IN ($placeholders) AND restaurant_id = ? AND status = 'available'");
                $params = array_merge($selected_items, [$restaurant_id]);
                $types = str_repeat('i', count($selected_items)) . 'i';
                $refs = [];
                foreach ($params as $k => $v) $refs[] = &$params[$k];
                call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
                $stmt->execute();
                $result = $stmt->get_result();

                $prices = [];
                while ($row = $result->fetch_assoc()) {
                    $prices[$row['id']] = ['name' => $row['item_name'], 'price' => $row['price']];
                }
                $stmt->close();

                try {
                    $conn->begin_transaction();
                    $total = 0;
                    $items_data = [];

                    foreach ($selected_items as $item_id_str) {
                        $item_id = (int)$item_id_str;
                        if (!isset($prices[$item_id])) continue;

                        $qty = max(1, (int)($quantities[$item_id_str] ?? 1));
                        $note = trim($notes_arr[$item_id_str] ?? '');
                        $price = $prices[$item_id]['price'];
                        $old_qty = $current_qty[$item_id] ?? 0;
                        $diff = $qty - $old_qty;

                        $total += $qty * $price;
                        $items_data[] = ['item_id' => $item_id, 'quantity' => $qty, 'notes' => $note];

                        if ($diff != 0) {
                            $item_name = $prices[$item_id]['name'];
                            $check = $conn->prepare("SELECT quantity FROM stock_inventory WHERE restaurant_id = ? AND stock_name = ?");
                            $check->bind_param("is", $restaurant_id, $item_name);
                            $check->execute();
                            $stock_row = $check->get_result()->fetch_assoc();
                            $check->close();

                            if ($stock_row) {
                                if ($diff > 0 && $stock_row['quantity'] < $diff) {
                                    throw new Exception("Not enough stock for <strong>$item_name</strong>.");
                                }
                                $op = $diff > 0 ? '-' : '+';
                                $abs_diff = abs($diff);
                                $update = $conn->prepare("UPDATE stock_inventory SET quantity = quantity $op ? WHERE restaurant_id = ? AND stock_name = ?");
                                $update->bind_param("iis", $abs_diff, $restaurant_id, $item_name);
                                $update->execute();
                                $update->close();
                            }
                        }
                    }

                    $old_data = [
                        'items' => $cur_items,
                        'total_amount' => $order['total_amount'],
                        'table_number' => $order['table_number']
                    ];
                    $remarks = "Order edited by superadmin";
                    $change_time = nepali_date_time();

                    $archive = $conn->prepare("INSERT INTO old_order (order_id, restaurant_id, changed_by, change_time, old_data, remarks) VALUES (?, ?, ?, ?, ?, ?)");
                    $json_old = json_encode($old_data);
                    $archive->bind_param("iiisss", $order_id, $restaurant_id, $user_id, $change_time, $json_old, $remarks);
                    $archive->execute();
                    $archive->close();

                    $items_json = json_encode($items_data);
                    $updated_at_bs = nepali_date_time();

                    $upd = $conn->prepare("UPDATE orders SET table_number = ?, items = ?, total_amount = ?, updated_at = ? WHERE id = ? AND restaurant_id = ?");
                    $upd->bind_param("ssdssi", $table_identifier, $items_json, $total, $updated_at_bs, $order_id, $restaurant_id);
                    $upd->execute();
                    $upd->close();

                    $conn->commit();
                    $success_msg = "Order updated successfully!";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_msg = $e->getMessage();
                }
            }
        }
    }
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
    <title>Edit Orders - <?= htmlspecialchars($restaurant_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../admin.css" rel="stylesheet">
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

<?php include 'navbar.php'; ?>

<div class="container py-4 py-md-5">
    <h3 class="mb-4 text-center text-success fw-bold">Edit Orders - <?= htmlspecialchars($restaurant_name) ?></h3>

    <div class="text-center mb-4">
        <a href="view_branch.php" class="btn btn-secondary px-5">Back</a>
    </div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success text-center p-4 rounded shadow-sm mb-4">
        <strong><?= htmlspecialchars($success_msg) ?></strong>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="alert alert-danger text-center p-4 rounded shadow-sm mb-4">
        <?= nl2br(htmlspecialchars($error_msg)) ?>
    </div>
    <?php endif; ?>

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
                                <input type="hidden" name="table_identifier" value="<?= htmlspecialchars($order['table_number']) ?>">

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Edit Items</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" placeholder="Search items..." id="searchInput_<?= $order['id'] ?>">
                                    </div>
                                </div>

                                <?php 
                                $order_items = json_decode($order['items'], true) ?? [];
                                $order_categories = array_unique(array_merge(array_column($order_items, 'category'), $categories));
                                sort($order_categories);
                                ?>
                                <ul class="nav nav-tabs mb-3" id="catTabs_<?= $order['id'] ?>">
                                    <?php foreach ($order_categories as $idx => $cat): ?>
                                    <li class="nav-item">
                                        <button class="nav-link <?= $idx === 0 ? 'active' : '' ?>" type="button" data-order="<?= $order['id'] ?>" data-cat="<?= htmlspecialchars($cat ?: 'Uncategorized') ?>">
                                            <?= htmlspecialchars($cat ?: 'Uncategorized') ?>
                                        </button>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>

                                <div class="row g-3" id="menuGrid_<?= $order['id'] ?>"></div>

                                <div class="text-center mt-4">
                                    <button type="button" class="btn btn-primary me-2" id="updateBtn_<?= $order['id'] ?>" data-order="<?= $order['id'] ?>">
                                        Update Order
                                    </button>
                                    <button type="button" class="btn btn-danger" onclick="showDeleteModal(<?= $order['id'] ?>)">
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
<div class="modal fade" id="deleteModal" tabindex="-1">
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

    const confirmModal = new bootstrap.Modal('#confirmModal');
    const deleteModal = new bootstrap.Modal('#deleteModal');

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function showCenterToast(message) {
        document.getElementById('toastMessage').textContent = message;
        document.getElementById('centerToast').style.display = 'block';
        setTimeout(() => document.getElementById('centerToast').style.display = 'none', 4000);
    }

    function renderCategory(orderId, cat) {
        const grid = document.getElementById('menuGrid_' + orderId);
        grid.innerHTML = '';

        const filtered = menuItems.filter(item => (item.category || '').trim() === cat);
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
                                <input type="text" class="form-control notes-input"
                                       value="${escapeHtml(state.notes)}" placeholder="e.g. less spicy">
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

    // Tab switching
    document.querySelectorAll('[id^="catTabs_"] button').forEach(btn => {
        btn.addEventListener('click', function() {
            const orderId = this.dataset.order;
            const cat = this.dataset.cat;
            document.querySelectorAll(`#catTabs_${orderId} button`).forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            renderCategory(orderId, cat);
        });
    });

    // Checkbox & input listeners
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
                delete orderState[orderId][itemId];
            }
        }
    });

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

    // Update button - show confirmation modal
    document.querySelectorAll('[id^="updateBtn_"]').forEach(btn => {
        btn.addEventListener('click', function() {
            currentOrderId = this.dataset.order;
            const form = document.getElementById('editForm_' + currentOrderId);
            const table = form.querySelector('input[name="table_identifier"]').value.trim();
            const selected = Object.keys(orderState[currentOrderId] || {});

            if (selected.length === 0) {
                showCenterToast('Please select at least one item to update the order.');
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
            confirmModal.show();
        });
    });

    // Confirm update - create hidden fields from array and submit
    document.getElementById('confirmUpdateBtn').addEventListener('click', () => {
        const form = document.getElementById('editForm_' + currentOrderId);

        // Remove any old hidden fields
        form.querySelectorAll('input[type="hidden"][name^="selected_items"], input[type="hidden"][name^="quantities["], input[type="hidden"][name^="notes["]').forEach(el => el.remove());

        // Create hidden fields from orderState array (all items, all categories)
        Object.keys(orderState[currentOrderId] || {}).forEach(itemId => {
            const { qty, notes } = orderState[currentOrderId][itemId];

            const hiddenId = document.createElement('input');
            hiddenId.type = 'hidden';
            hiddenId.name = 'selected_items[]';
            hiddenId.value = itemId;
            form.appendChild(hiddenId);

            const hiddenQty = document.createElement('input');
            hiddenQty.type = 'hidden';
            hiddenQty.name = `quantities[${itemId}]`;
            hiddenQty.value = qty;
            form.appendChild(hiddenQty);

            const hiddenNotes = document.createElement('input');
            hiddenNotes.type = 'hidden';
            hiddenNotes.name = `notes[${itemId}]`;
            hiddenNotes.value = notes;
            form.appendChild(hiddenNotes);
        });

        form.submit();
    });

    function showDeleteModal(id) {
        currentOrderId = id;
        deleteModal.show();
    }

    document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
        const form = document.getElementById('editForm_' + currentOrderId);
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'delete_order';
        input.value = '1';
        form.appendChild(input);
        form.submit();
    });

    // Initial load
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[id^="catTabs_"] button:first-child').forEach(first => {
            first.classList.add('active');
            const orderId = first.dataset.order;
            const cat = first.dataset.cat;
            renderCategory(orderId, cat);
        });
    });
</script>

<?php include '../Common/footer.php'; ?>
</body>
</html>