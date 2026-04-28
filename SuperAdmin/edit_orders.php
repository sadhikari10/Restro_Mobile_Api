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

    if ($order) {
        $cur_items = json_decode($order['items'], true) ?? [];
        $current_qty = [];
        foreach ($cur_items as $item) {
            $current_qty[$item['item_id']] = $item['quantity'];
        }

        // --- DELETE ORDER LOGIC ---
        if (isset($_POST['delete_order']) && $_POST['delete_order'] === '1') {
            $reason = trim($_POST['delete_reason'] ?? '');
            if (empty($reason)) {
                $error_msg = "Delete failed: A reason is required.";
            } else {
                try {
                    $conn->begin_transaction();
                    
                    // Archive with Reason
                    $old_data = json_encode(['items' => $cur_items, 'total' => $order['total_amount']]);
                    $change_time = nepali_date_time();
                    $archive = $conn->prepare("INSERT INTO old_order (order_id, restaurant_id, changed_by, change_time, old_data, remarks) VALUES (?, ?, ?, ?, ?, ?)");
                    $archive->bind_param("iiisss", $order_id, $restaurant_id, $user_id, $change_time, $old_data, $reason);
                    $archive->execute();

                    // Restore Stock
                    foreach ($cur_items as $item) {
                        $it_stmt = $conn->prepare("SELECT item_name FROM menu_items WHERE id = ?");
                        $it_stmt->bind_param("i", $item['item_id']);
                        $it_stmt->execute();
                        $it_row = $it_stmt->get_result()->fetch_assoc();
                        if ($it_row) {
                            $restore = $conn->prepare("UPDATE stock_inventory SET quantity = quantity + ? WHERE restaurant_id = ? AND stock_name = ?");
                            $restore->bind_param("iis", $item['quantity'], $restaurant_id, $it_row['item_name']);
                            $restore->execute();
                        }
                    }

                    $del = $conn->prepare("DELETE FROM orders WHERE id = ? AND restaurant_id = ?");
                    $del->bind_param("ii", $order_id, $restaurant_id);
                    $del->execute();

                    $conn->commit();
                    $success_msg = "Order deleted successfully.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_msg = "Delete failed.";
                }
            }
        } 
        // --- UPDATE ORDER LOGIC ---
        else {
            $selected_items = $_POST['selected_items'] ?? [];
            $quantities = $_POST['quantities'] ?? [];
            $notes_arr = $_POST['notes'] ?? [];
            $table_id = trim($_POST['table_identifier'] ?? $order['table_number']);

            try {
                $conn->begin_transaction();
                $total = 0; $items_data = [];

                foreach ($selected_items as $item_id) {
                    $item_id = (int)$item_id;
                    $stmt = $conn->prepare("SELECT item_name, price FROM menu_items WHERE id = ?");
                    $stmt->bind_param("i", $item_id);
                    $stmt->execute();
                    $mi = $stmt->get_result()->fetch_assoc();
                    
                    if ($mi) {
                        $qty = max(1, (int)($quantities[$item_id] ?? 1));
                        $note = trim($notes_arr[$item_id] ?? '');
                        $total += $qty * $mi['price'];
                        $items_data[] = ['item_id' => $item_id, 'quantity' => $qty, 'notes' => $note];

                        // Stock management
                        $old_q = $current_qty[$item_id] ?? 0;
                        $diff = $qty - $old_q;
                        if ($diff != 0) {
                            $op = $diff > 0 ? '-' : '+';
                            $abs_diff = abs($diff);
                            $upd_stock = $conn->prepare("UPDATE stock_inventory SET quantity = quantity $op ? WHERE restaurant_id = ? AND stock_name = ?");
                            $upd_stock->bind_param("iis", $abs_diff, $restaurant_id, $mi['item_name']);
                            $upd_stock->execute();
                        }
                    }
                }

                $items_json = json_encode($items_data);
                $upd_at = nepali_date_time();
                $upd = $conn->prepare("UPDATE orders SET table_number = ?, items = ?, total_amount = ?, updated_at = ? WHERE id = ?");
                $upd->bind_param("ssdsi", $table_id, $items_json, $total, $upd_at, $order_id);
                $upd->execute();

                $conn->commit();
                $success_msg = "Order updated successfully.";
            } catch (Exception $e) { $conn->rollback(); $error_msg = "Update failed."; }
        }
    }
}

/* ==================== FETCH DATA ==================== */
$stmt = $conn->prepare("SELECT o.*, u.username FROM orders o LEFT JOIN users u ON o.order_by = u.id WHERE o.restaurant_id = ? AND o.status = 'preparing' ORDER BY o.id DESC");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("SELECT id, item_name, price, category FROM menu_items WHERE restaurant_id = ? AND status = 'available'");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$menu_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$categories = array_unique(array_filter(array_column($menu_items, 'category')));
sort($categories);

$stock = [];
$res = $conn->query("SELECT stock_name, quantity FROM stock_inventory WHERE restaurant_id = $restaurant_id");
while($r = $res->fetch_assoc()) $stock[$r['stock_name']] = $r['quantity'];
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Orders - <?= $restaurant_name ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .item-card { transition: 0.2s; border: 1px solid #eee; }
        .item-card:hover { border-color: #198754; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .order-card-wrapper { display: none; }
        .table-selector-card { cursor: pointer; transition: 0.3s; }
        .table-selector-card:hover { background: #198754; color: white; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-success m-0">Edit Orders</h3>
        <a href="view_branch.php" class="btn btn-secondary shadow-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to Branch
        </a>
    </div>

    <?php if($success_msg): ?><div class="alert alert-success"><?= $success_msg ?></div><?php endif; ?>
    <?php if($error_msg): ?><div class="alert alert-danger"><?= $error_msg ?></div><?php endif; ?>

    <div id="tableSelectionGrid" class="row g-3 justify-content-center mb-5">
    <?php if (empty($orders)): ?>
        <div class="col-12 text-center py-5">
            <div class="mb-4">
                <i class="bi bi-clipboard-x text-muted" style="font-size: 4rem;"></i>
            </div>
            <h4 class="text-muted">No orders are currently being prepared.</h4>
            <p class="mb-4">All orders are either completed or haven't been placed yet.</p>
            <a href="view_branch.php" class="btn btn-primary px-5 py-2 fw-bold shadow-sm">
                Go Back to View Branch
            </a>
        </div>
    <?php else: ?>
        <h5 class="text-center mb-4 text-muted col-12">Select a Table to Edit:</h5>
        <?php foreach ($orders as $order): ?>
        <div class="col-6 col-md-3">
            <div class="card table-selector-card shadow-sm text-center py-4 border-0" onclick="showOrderForm(<?= $order['id'] ?>)">
                <span class="text-muted small text-uppercase fw-bold">Table Name</span>
                <h3 class="mb-0 text-success fw-bold"><?= htmlspecialchars($order['table_number']) ?></h3>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>
    <?php foreach ($orders as $order): ?>
    <div class="order-card-wrapper" id="orderBlock_<?= $order['id'] ?>">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Table: <?= htmlspecialchars($order['table_number']) ?></h5>
                <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">Back to List</button>
            </div>
            <div class="card-body">
                <form method="POST" id="editForm_<?= $order['id'] ?>">
                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold">Search Menu Items</label>
                        <input type="text" class="form-control form-control-lg shadow-sm" placeholder="Search across all categories..." id="searchInput_<?= $order['id'] ?>" oninput="handleGlobalSearch(<?= $order['id'] ?>)">
                    </div>

                    <ul class="nav nav-pills mb-3" id="catTabs_<?= $order['id'] ?>">
                        <?php foreach ($categories as $idx => $cat): ?>
                        <li class="nav-item">
                            <button class="nav-link <?= $idx===0?'active':'' ?>" type="button" onclick="switchCategory(<?= $order['id'] ?>, '<?= htmlspecialchars($cat) ?>', this)"><?= $cat ?></button>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="row g-3" id="menuGrid_<?= $order['id'] ?>"></div>

                    <div class="mt-5 border-top pt-4 text-center">
                        <button type="button" class="btn btn-success px-5 py-2 fw-bold" onclick="prepareUpdate(<?= $order['id'] ?>)">Update Order</button>
                        <button type="button" class="btn btn-danger px-5 py-2 fw-bold ms-2" onclick="showDeleteModal(<?= $order['id'] ?>)">Delete Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-success text-white"><h5>Confirm Changes</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="orderSummary"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-success" id="finalUpdateBtn">Confirm & Update</button></div></div></div></div>

<div class="modal fade" id="deleteModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-danger text-white"><h5>Delete Order</h5></div><div class="modal-body">
    <p>Are you sure you want to delete this order? This cannot be undone.</p>
    <label class="form-label fw-bold mt-2">Reason for Deletion (Required):</label>
    <textarea id="deleteReason" class="form-control" rows="3" placeholder="e.g., Customer left, Wrong entry..."></textarea>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" onclick="submitDelete()">Delete Permanently</button></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const menuItems = <?= json_encode($menu_items) ?>;
    const stock = <?= json_encode($stock) ?>;
    let orderState = {};
    let currentOrderId = null;
    let currentCategory = "";

    function showOrderForm(id) {
        document.getElementById('tableSelectionGrid').style.display = 'none';
        document.getElementById('orderBlock_' + id).style.display = 'block';
        currentOrderId = id;
        const firstCat = document.querySelector(`#catTabs_${id} .nav-link.active`).innerText;
        switchCategory(id, firstCat, document.querySelector(`#catTabs_${id} .nav-link.active`));
    }

    function switchCategory(orderId, cat, btn) {
        document.querySelectorAll(`#catTabs_${orderId} .nav-link`).forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentCategory = cat;
        renderItems(orderId, item => item.category === cat);
    }

    function handleGlobalSearch(orderId) {
        const query = document.getElementById('searchInput_' + orderId).value.toLowerCase();
        const tabContainer = document.getElementById('catTabs_' + orderId);
        
        if (query.length > 0) {
            tabContainer.style.display = 'none'; // Hide tabs to focus on search results
            renderItems(orderId, item => item.item_name.toLowerCase().includes(query));
        } else {
            tabContainer.style.display = 'flex'; // Show tabs back
            renderItems(orderId, item => item.category === currentCategory);
        }
    }

    function renderItems(orderId, filterFn) {
        const grid = document.getElementById('menuGrid_' + orderId);
        grid.innerHTML = '';
        const filtered = menuItems.filter(filterFn);

        filtered.forEach(item => {
            const state = orderState[orderId][item.id] || null;
            const checked = state !== null;
            const itemStock = stock[item.item_name] ?? 'N/A';

            const col = document.createElement('div');
            col.className = 'col-6 col-md-4 col-lg-3';
            col.innerHTML = `
                <div class="card h-100 item-card">
                    <div class="card-body p-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="chk_${orderId}_${item.id}" ${checked?'checked':''} onchange="toggleItem(${orderId}, ${item.id})">
                            <label class="form-check-label fw-bold d-block" for="chk_${orderId}_${item.id}">${item.item_name}</label>
                        </div>
                        <small class="text-success d-block mb-2">Rs ${item.price} | Stock: ${itemStock}</small>
                        <div id="input_${orderId}_${item.id}" style="display:${checked?'block':'none'}">
                            <input type="number" class="form-control form-control-sm mb-1" value="${state?.qty || 1}" oninput="updateState(${orderId}, ${item.id}, 'qty', this.value)" placeholder="Qty">
                            <input type="text" class="form-control form-control-sm" value="${state?.notes || ''}" oninput="updateState(${orderId}, ${item.id}, 'notes', this.value)" placeholder="Notes">
                        </div>
                    </div>
                </div>`;
            grid.appendChild(col);
        });
    }

    // Initialize state with existing items
    <?php foreach($orders as $o): ?>
    orderState[<?= $o['id'] ?>] = {};
    <?php $items = json_decode($o['items'], true) ?? []; foreach($items as $i): ?>
    orderState[<?= $o['id'] ?>][<?= $i['item_id'] ?>] = { qty: <?= $i['quantity'] ?>, notes: <?= json_encode($i['notes'] ?? '') ?> };
    <?php endforeach; endforeach; ?>

    function toggleItem(orderId, itemId) {
        const isChecked = document.getElementById(`chk_${orderId}_${itemId}`).checked;
        const inputDiv = document.getElementById(`input_${orderId}_${itemId}`);
        if(isChecked) {
            orderState[orderId][itemId] = { qty: 1, notes: "" };
            inputDiv.style.display = 'block';
        } else {
            delete orderState[orderId][itemId];
            inputDiv.style.display = 'none';
        }
    }

    function updateState(orderId, itemId, key, val) {
        if(orderState[orderId][itemId]) orderState[orderId][itemId][key] = val;
    }

    function prepareUpdate(orderId) {
        const selected = Object.keys(orderState[orderId]);
        if(selected.length === 0) return alert("Select at least one item.");
        
        let html = '<ul class="list-group">';
        selected.forEach(sid => {
            const menu = menuItems.find(m => m.id == sid);
            html += `<li class="list-group-item d-flex justify-content-between"><span>${menu.item_name} x ${orderState[orderId][sid].qty}</span></li>`;
        });
        document.getElementById('orderSummary').innerHTML = html + '</ul>';
        new bootstrap.Modal('#confirmModal').show();
    }

    document.getElementById('finalUpdateBtn').addEventListener('click', () => {
        const form = document.getElementById('editForm_' + currentOrderId);
        Object.keys(orderState[currentOrderId]).forEach(id => {
            const s = orderState[currentOrderId][id];
            form.insertAdjacentHTML('beforeend', `<input type="hidden" name="selected_items[]" value="${id}">`);
            form.insertAdjacentHTML('beforeend', `<input type="hidden" name="quantities[${id}]" value="${s.qty}">`);
            form.insertAdjacentHTML('beforeend', `<input type="hidden" name="notes[${id}]" value="${s.notes}">`);
        });
        form.submit();
    });

    function showDeleteModal(id) { currentOrderId = id; new bootstrap.Modal('#deleteModal').show(); }
    
    function submitDelete() {
        const reason = document.getElementById('deleteReason').value.trim();
        if(!reason) return alert("You must provide a reason for deletion.");
        
        const form = document.getElementById('editForm_' + currentOrderId);
        form.insertAdjacentHTML('beforeend', `<input type="hidden" name="delete_order" value="1">`);
        form.insertAdjacentHTML('beforeend', `<input type="hidden" name="delete_reason" value="${reason}">`);
        form.submit();
    }
</script>

</body>
</html>