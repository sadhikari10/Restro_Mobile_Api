<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'staff' || empty($_SESSION['restaurant_id'])) {
    header('Location: ../common/login.php');
    exit;
}

$restaurant_id = $_SESSION['restaurant_id'];
$staff_id = $_SESSION['user_id'];
$error_msg = '';
$success_msg = isset($_GET['success']) ? 'Order placed successfully!' : '';

// AJAX: Restore stock when item unchecked
if (isset($_POST['restore_stock']) && $_POST['restore_stock'] == '1') {
    $item_name = trim($_POST['item_name'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);
    $rest_id = (int)($_POST['restaurant_id'] ?? 0);

    if ($item_name && $quantity > 0 && $rest_id === $restaurant_id) {
        $check = $conn->prepare("SELECT id FROM stock_inventory WHERE restaurant_id = ? AND stock_name = ?");
        $check->bind_param("is", $restaurant_id, $item_name);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $restore = $conn->prepare("UPDATE stock_inventory SET quantity = quantity + ? WHERE restaurant_id = ? AND stock_name = ?");
            $restore->bind_param("iis", $quantity, $restaurant_id, $item_name);
            $restore->execute();
            $restore->close();
        }
        $check->close();
    }
    exit;
}

// Main order placement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['restore_stock'])) {
    $selected_items = $_POST['selected_items'] ?? [];
    $quantities = $_POST['quantities'] ?? [];
    $notes_arr = $_POST['notes'] ?? [];
    $table_identifier = trim($_POST['table_identifier'] ?? '');

    if (empty($table_identifier)) {
        $error_msg = 'Please enter Table Number / Customer Name / Phone.';
    } elseif (empty($selected_items)) {
        $error_msg = 'Please select at least one item.';
    } else {
        $placeholders = str_repeat('?,', count($selected_items) - 1) . '?';
        $stmt = $conn->prepare("SELECT id, item_name, price FROM menu_items WHERE id IN ($placeholders) AND restaurant_id = ?");
        $types = str_repeat('i', count($selected_items)) . 'i';
        $params = array_merge(array_map('intval', $selected_items), [$restaurant_id]);
        $refs = [];
        foreach ($params as $k => $v) $refs[] = &$params[$k];
        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
        $stmt->execute();
        $result = $stmt->get_result();

        $prices = [];
        while ($row = $result->fetch_assoc()) {
            $prices[$row['id']] = ['price' => $row['price'], 'name' => $row['item_name']];
        }
        $stmt->close();

        if (empty($prices)) {
            $error_msg = "Selected items are no longer valid.";
        } else {
            $total = 0.0;
            $items_data = [];

            foreach ($selected_items as $item_id_str) {
                $item_id = (int)$item_id_str;
                if (!isset($prices[$item_id])) continue;

                $qty = max(1, (int)($quantities[$item_id_str] ?? 1));
                $note = trim($notes_arr[$item_id_str] ?? '');

                $price = $prices[$item_id]['price'];
                $total += $qty * $price;
                $items_data[] = ['item_id' => $item_id, 'quantity' => $qty, 'notes' => $note];
            }

            try {
                $conn->begin_transaction();

                foreach ($items_data as $it) {
                    $name = $prices[$it['item_id']]['name'];
                    $qty = $it['quantity'];

                    $st = $conn->prepare("SELECT quantity FROM stock_inventory WHERE restaurant_id = ? AND stock_name = ? FOR UPDATE");
                    $st->bind_param("is", $restaurant_id, $name);
                    $st->execute();
                    $res = $st->get_result();
                    if ($row = $res->fetch_assoc()) {
                        if ($row['quantity'] < $qty) {
                            throw new Exception("Insufficient stock for <strong>$name</strong>: only {$row['quantity']} available.");
                        }
                    }
                    $st->close();
                }

                $items_json = json_encode($items_data);
                $now = nepali_date_time();

                $ins = $conn->prepare("INSERT INTO orders (restaurant_id, table_number, items, total_amount, status, created_at, order_by) VALUES (?, ?, ?, ?, 'preparing', ?, ?)");
                $ins->bind_param("issdsi", $restaurant_id, $table_identifier, $items_json, $total, $now, $staff_id);
                $ins->execute();
                $ins->close();

                foreach ($items_data as $it) {
                    $name = $prices[$it['item_id']]['name'];
                    $qty = $it['quantity'];
                    $deduct = $conn->prepare("UPDATE stock_inventory SET quantity = quantity - ? WHERE restaurant_id = ? AND stock_name = ?");
                    $deduct->bind_param("iis", $qty, $restaurant_id, $name);
                    $deduct->execute();
                    $deduct->close();
                }

                $conn->commit();
                header("Location: orders.php?success=1");
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = $e->getMessage();
            }
        }
    }
}

// Fetch menu items and categories
$stmt = $conn->prepare("SELECT id, item_name, price, description, category FROM menu_items WHERE restaurant_id = ? AND status = 'available' ORDER BY item_name");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$res = $stmt->get_result();
$menu_items = [];
$categories = [];
while ($row = $res->fetch_assoc()) {
    $menu_items[] = $row;
    $cat = trim($row['category'] ?? '');
    if ($cat && !in_array($cat, $categories)) {
        $categories[] = $cat;
    }
}
sort($categories);
$stmt->close();

// Fetch stock
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
    <title>Take Order - Staff</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="staff.css" rel="stylesheet">
    <style>
        .item-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); transition: all 0.3s ease; }
        .item-details { display: none; margin-top: 12px; }
        .stock-available { color: #17a2b8; font-weight: 500; }
        .inline-message { max-width: 600px; margin: 0 auto; background-color: #fff; border: 1px solid; font-size: 1.1rem; display: inline-block; padding: 1rem; border-radius: 8px; }
        .inline-message.success { border-color: #d4edda; background-color: #f8fff9; color: #155724; }
        .inline-message.error { border-color: #f5c6cb; background-color: #fff5f5; color: #721c24; }
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
    </style>
</head>
<body class="bg-light">

<div class="container py-4 py-md-5">
    <h3 class="mb-4 text-center text-success fw-bold">Take Order</h3>

    <div class="text-center mb-4">
        <a href="dashboard.php" class="btn btn-secondary px-5">Back to Dashboard</a>
    </div>

    <?php if ($success_msg): ?>
    <div class="text-center mb-4">
        <div class="inline-message success">
            <i class="bi bi-check-circle-fill me-2"></i>
            <strong><?= htmlspecialchars($success_msg) ?></strong>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="text-center mb-4">
        <div class="inline-message error">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= nl2br(htmlspecialchars($error_msg)) ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" id="orderForm">
                <div class="mb-4">
                    <label class="form-label fw-bold">Table Number / Customer Name / Phone <span class="text-danger">*</span></label>
                    <input type="text" name="table_identifier" id="table_identifier" class="form-control form-control-lg"
                           placeholder="e.g. Table 7, Ramesh, 98xxxxxxxx" required>
                </div>

                <div class="mb-4">
                    <input type="text" id="searchInput" class="form-control form-control-lg" placeholder="Search items in current category...">
                </div>

                <?php if (!empty($categories)): ?>
                <ul class="nav nav-tabs mb-4 flex-wrap" id="categoryTabs">
                    <?php foreach ($categories as $index => $cat): ?>
                    <li class="nav-item">
                        <button class="nav-link <?= $index === 0 ? 'active' : '' ?>" type="button" data-category="<?= htmlspecialchars($cat) ?>">
                            <?= htmlspecialchars($cat) ?>
                        </button>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <div class="row g-3" id="menuGrid"></div>

                <div class="text-center mt-5">
                    <button type="button" class="btn btn-primary btn-lg px-5" id="reviewOrderBtn">Review & Place Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Centered Toast -->
<div id="centerToast" class="card border-0">
    <div class="card-body text-center">
        <i class="bi bi-exclamation-triangle fs-1 text-danger mb-3"></i>
        <p id="toastMessage" class="fw-bold fs-5"></p>
    </div>
</div>

<!-- Confirm Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Confirm Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="orderSummary"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="placeOrderBtn">Place Order</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const menuItems = <?= json_encode($menu_items) ?>;
    const stock = <?= json_encode($stock) ?>;
    const categories = <?= json_encode($categories) ?>;

    let orderData = { table: '', items: {} };
    const STORAGE_KEY = 'staff_order_temp';

    const grid = document.getElementById('menuGrid');

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function saveOrderData() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(orderData));
    }

    function loadOrderData() {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            try {
                orderData = JSON.parse(saved);
            } catch (e) {
                orderData = { table: '', items: {} };
            }
        }
    }

    function clearOrderData() {
        orderData = { table: '', items: {} };
        localStorage.removeItem(STORAGE_KEY);
        document.getElementById('table_identifier').value = '';
        if (categories.length > 0) {
            renderCategory(categories[0]);
        }
    }

    function renderCategory(category) {
        grid.innerHTML = '';

        const filtered = menuItems.filter(item => (item.category || '').trim() === category);
        if (filtered.length === 0) {
            grid.innerHTML = '<div class="col-12 text-center py-5 text-muted">No items in this category</div>';
            return;
        }

        filtered.forEach(item => {
            const saved = orderData.items[item.id] || { qty: 1, notes: '' };
            const checked = !!orderData.items[item.id];
            const hasStock = stock.hasOwnProperty(item.item_name);
            const stockDisplay = hasStock ? stock[item.item_name] : null;

            const div = document.createElement('div');
            div.className = 'col-6 col-md-4 col-lg-3';
            div.innerHTML = `
                <div class="card h-100 border-0 shadow-sm item-card">
                    <div class="card-body p-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="${item.id}" id="item_${item.id}" ${checked ? 'checked' : ''}>
                            <label class="form-check-label fw-bold small d-block" for="item_${item.id}">
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
                        <div id="details_${item.id}" style="display:${checked ? 'block' : 'none'}; margin-top:12px;">
                            <div class="input-group input-group-sm mb-2">
                                <span class="input-group-text">Qty</span>
                                <input type="number" class="form-control qty-input" value="${saved.qty}" min="1" data-id="${item.id}">
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">Notes</span>
                                <input type="text" class="form-control notes-input" value="${escapeHtml(saved.notes)}" placeholder="e.g. less spicy" data-id="${item.id}">
                            </div>
                        </div>
                    </div>
                </div>
            `;
            grid.appendChild(div);
        });
    }

    // Checkbox change - with stock restore
    grid.addEventListener('change', function(e) {
        if (e.target.type === 'checkbox') {
            const itemId = e.target.value;
            const details = document.getElementById('details_' + itemId);
            const qtyInput = details.querySelector('.qty-input');
            const notesInput = details.querySelector('.notes-input');

            if (e.target.checked) {
                details.style.display = 'block';
                const qty = parseInt(qtyInput.value) || 1;
                const notes = notesInput.value.trim();
                orderData.items[itemId] = { qty, notes };
            } else {
                details.style.display = 'none';
                const qtyToRestore = parseInt(qtyInput.value) || 1;

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

                qtyInput.value = 1;
                notesInput.value = '';
                delete orderData.items[itemId];
            }
            saveOrderData();
        }
    });

    // Input changes
    grid.addEventListener('input', function(e) {
        if (e.target.classList.contains('qty-input') || e.target.classList.contains('notes-input')) {
            const itemId = e.target.dataset.id;
            const checkbox = document.getElementById('item_' + itemId);
            if (checkbox && checkbox.checked) {
                const qty = parseInt(e.target.closest('.card').querySelector('.qty-input').value) || 1;
                const notes = e.target.closest('.card').querySelector('.notes-input').value.trim();
                orderData.items[itemId] = { qty, notes };
                saveOrderData();
            }
        }
    });

    // Tab switching
    document.querySelectorAll('#categoryTabs button').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('#categoryTabs button').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            renderCategory(this.dataset.category);
            document.getElementById('searchInput').value = '';
        });
    });

    // Search
    document.getElementById('searchInput').addEventListener('input', function() {
        const term = this.value.toLowerCase().trim();
        grid.querySelectorAll('.col-6, .col-md-4, .col-lg-3').forEach(card => {
            const text = card.textContent.toLowerCase();
            card.style.display = text.includes(term) ? 'block' : 'none';
        });
    });

    // Review Order
    document.getElementById('reviewOrderBtn').addEventListener('click', function() {
        orderData.table = document.getElementById('table_identifier').value.trim();
        saveOrderData();

        const selected = Object.keys(orderData.items);
        if (selected.length === 0) {
            showCenterToast('Please select at least one item.');
            return;
        }
        if (!orderData.table) {
            showCenterToast('Please enter Table Number / Customer Name / Phone.');
            return;
        }

        let html = `<p class="fw-bold fs-5 mb-3">Order for: <span class="text-primary">${escapeHtml(orderData.table)}</span></p>`;
        html += '<ul class="list-group list-group-flush">';
        let total = 0;

        selected.forEach(id => {
            const item = menuItems.find(i => i.id == id);
            if (!item) return;
            const { qty, notes } = orderData.items[id];
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

    // Confirm and submit
    document.getElementById('placeOrderBtn').addEventListener('click', () => {
        const form = document.getElementById('orderForm');

        form.querySelectorAll('input[type="hidden"]').forEach(el => el.remove());

        Object.keys(orderData.items).forEach(id => {
            const { qty, notes } = orderData.items[id];

            const hiddenId = document.createElement('input');
            hiddenId.type = 'hidden';
            hiddenId.name = 'selected_items[]';
            hiddenId.value = id;
            form.appendChild(hiddenId);

            const hiddenQty = document.createElement('input');
            hiddenQty.type = 'hidden';
            hiddenQty.name = `quantities[${id}]`;
            hiddenQty.value = qty;
            form.appendChild(hiddenQty);

            const hiddenNotes = document.createElement('input');
            hiddenNotes.type = 'hidden';
            hiddenNotes.name = `notes[${id}]`;
            hiddenNotes.value = notes;
            form.appendChild(hiddenNotes);
        });

        form.submit();
    });

    function showCenterToast(message) {
        document.getElementById('toastMessage').textContent = message;
        document.getElementById('centerToast').style.display = 'block';
        setTimeout(() => document.getElementById('centerToast').style.display = 'none', 4000);
    }

    // Initial load
    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('success') === '1') {
            clearOrderData();
        } else {
            loadOrderData();
            document.getElementById('table_identifier').value = orderData.table || '';
        }

        if (categories.length > 0) {
            renderCategory(categories[0]);
            document.querySelector('#categoryTabs button').classList.add('active');
        }

        document.getElementById('table_identifier').addEventListener('input', () => {
            orderData.table = document.getElementById('table_identifier').value.trim();
            saveOrderData();
        });
    });
</script>

<?php include '../Common/footer.php'; ?>
</body>
</html>