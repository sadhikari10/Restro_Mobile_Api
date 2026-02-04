<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

date_default_timezone_set('Asia/Kathmandu');

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../login.php');
    exit;
}
if (empty($_SESSION['current_restaurant_id'])) {
    header('Location: index.php');
    exit;
}

$restaurant_id = $_SESSION['current_restaurant_id'];
$error_msg = '';
$success_msg = '';

// Flash success – only shown immediately after redirect
if (isset($_SESSION['just_placed_order']) && $_SESSION['just_placed_order'] === true) {
    $success_msg = 'Order placed successfully!';
    unset($_SESSION['just_placed_order']);   // disappears on next refresh
}

if (empty($_SESSION['order_token'])) {
    $_SESSION['order_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['order_token'];

/* ==================== POST HANDLER ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['token']) || $_POST['token'] !== $_SESSION['order_token']) {
        $error_msg = 'Invalid or duplicate submission. Please try again.';
    } else {
        unset($_SESSION['order_token']);

        $selected_items = $_POST['selected_items'] ?? [];
        $quantities = $_POST['quantities'] ?? [];
        $notes_arr = $_POST['notes'] ?? [];
        $table_identifier = trim($_POST['table_identifier'] ?? '');

        if (empty($selected_items)) {
            $error_msg = 'Please select at least one item.';
        } elseif (empty($table_identifier)) {
            $error_msg = 'Please enter Table Number or Customer Name/Phone.';
        } else {
            $placeholders = str_repeat('?,', count($selected_items) - 1) . '?';
            $query = "SELECT id, item_name, price FROM menu_items WHERE id IN ($placeholders) AND restaurant_id = ? AND status = 'available'";

            $stmt = $conn->prepare($query);
            $params = array_merge($selected_items, [$restaurant_id]);
            $types = str_repeat('i', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            $prices = [];
            while ($row = $result->fetch_assoc()) {
                $prices[$row['id']] = ['name' => $row['item_name'], 'price' => $row['price']];
            }
            $stmt->close();

            $total = 0.0;
            $items_data = [];
            $has_error = false;

            foreach ($selected_items as $item_id_str) {
                $item_id = (int)$item_id_str;
                $qty = max(1, (int)($quantities[$item_id_str] ?? 1));
                $note = trim($notes_arr[$item_id_str] ?? '');

                if (!isset($prices[$item_id])) {
                    $error_msg = "Item is no longer available.";
                    $has_error = true;
                    break;
                }

                $total += $qty * $prices[$item_id]['price'];
                $items_data[] = [
                    'item_id' => $item_id,
                    'quantity' => $qty,
                    'notes' => $note,
                    'item_name' => $prices[$item_id]['name']
                ];
            }

            if (!$has_error) {
                try {
                    $conn->begin_transaction();

                    $stock_to_deduct = [];
                    foreach ($items_data as $item) {
                        $name = $item['item_name'];
                        $qty = $item['quantity'];
                        $stock_to_deduct[$name] = ($stock_to_deduct[$name] ?? 0) + $qty;
                    }

                    foreach ($stock_to_deduct as $stock_name => $total_qty) {
                        if ($total_qty <= 0) continue;

                        $stock_stmt = $conn->prepare("SELECT id, quantity FROM stock_inventory WHERE restaurant_id = ? AND stock_name = ? FOR UPDATE");
                        $stock_stmt->bind_param("is", $restaurant_id, $stock_name);
                        $stock_stmt->execute();
                        $result = $stock_stmt->get_result();

                        if ($row = $result->fetch_assoc()) {
                            if ($row['quantity'] < $total_qty) {
                                $stock_stmt->close();
                                throw new Exception("Not enough stock for <strong>$stock_name</strong><br>Available: {$row['quantity']}, Required: $total_qty");
                            }

                            $new_quantity = $row['quantity'] - $total_qty;
                            $update_stmt = $conn->prepare("UPDATE stock_inventory SET quantity = ?, updated_at = NOW() WHERE id = ?");
                            $update_stmt->bind_param("ii", $new_quantity, $row['id']);
                            $update_stmt->execute();
                            $update_stmt->close();
                        }
                        $stock_stmt->close();
                    }

                    $items_json = json_encode(array_map(fn($i) => [
                        'item_id' => $i['item_id'],
                        'quantity' => $i['quantity'],
                        'notes' => $i['notes']
                    ], $items_data));

                    $ad_datetime = new DateTime('now', new DateTimeZone('Asia/Kathmandu'));
                    $ad_date = $ad_datetime->format('Y-m-d');
                    $ad_time = $ad_datetime->format('H:i:s');
                    $bs_date = ad_to_bs($ad_date);
                    $nepali_datetime = $bs_date . ' ' . $ad_time;

                    $stmt = $conn->prepare("INSERT INTO orders (restaurant_id, table_number, items, total_amount, status, created_at, order_by) VALUES (?, ?, ?, ?, 'preparing', ?, ?)");
                    $stmt->bind_param("issdsi", $restaurant_id, $table_identifier, $items_json, $total, $nepali_datetime, $_SESSION['user_id']);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();

                    $_SESSION['just_placed_order'] = true;
                    $_SESSION['order_token'] = bin2hex(random_bytes(16));

                    header("Location: take_order.php");
                    exit;

                } catch (Exception $e) {
                    $conn->rollback();
                    $error_msg = $e->getMessage();
                }
            }
        }
    }
}

/* ==================== FETCH DATA ==================== */
$stmt = $conn->prepare("SELECT id, item_name, price, description, category FROM menu_items WHERE restaurant_id = ? AND status = 'available' ORDER BY item_name");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$menu_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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

$table_identifier_val = $_POST['table_identifier'] ?? '';
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Take Order - SuperAdmin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../admin.css" rel="stylesheet">
    <style>
        .item-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); transition: all 0.3s ease; }
        .item-details { display: none; margin-top: 12px; }
        .stock-available { color: #17a2b8; font-weight: 500; }
        .placeholder-text { text-align: center; padding: 80px 20px; color: #6c757d; font-size: 1.4rem; min-height: 300px; display: flex; align-items: center; justify-content: center; }
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
        #success-alert { transition: opacity 0.8s ease-out; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container py-4 py-md-5">
    <h3 class="mb-4 text-center text-success fw-bold">Take Order (SuperAdmin)</h3>

    <div class="text-center mb-4">
        <a href="view_branch.php" class="btn btn-secondary px-5">Back</a>
    </div>

    <?php if ($success_msg): ?>
    <div class="my-4 text-center" id="success-alert">
        <div class="alert alert-success p-4 rounded shadow-sm">
            <i class="bi bi-check-circle-fill text-success me-2" style="font-size: 1.5rem;"></i>
            <strong><?= htmlspecialchars($success_msg) ?></strong>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="my-4 text-center">
        <div class="alert alert-danger p-4 rounded shadow-sm">
            <i class="bi bi-exclamation-triangle-fill text-danger me-2" style="font-size: 1.5rem;"></i>
            <?= nl2br(htmlspecialchars($error_msg)) ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" id="orderForm">
                <input type="hidden" name="token" value="<?= $token ?>">

                <div class="mb-4">
                    <label class="form-label fw-bold">Table Number / Customer Name / Phone <span class="text-danger">*</span></label>
                    <input type="text" name="table_identifier" id="table_identifier" class="form-control form-control-lg"
                           placeholder="e.g. Table 7, Ramesh, 98xxxxxxxx" value="<?= htmlspecialchars($table_identifier_val) ?>" required>
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

                <div id="placeholder" class="placeholder-text">Loading items...</div>
                <div class="row g-3" id="menuGrid"></div>

                <div id="hiddenFieldsContainer" style="display:none;"></div>

                <div class="text-center mt-5">
                    <button type="button" class="btn btn-primary btn-lg px-5" id="reviewOrderBtn">Review & Place Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Centered Toast Popup -->
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
// ────────────────────────────────────────────────
// Auto-hide success message after 5 seconds
// ────────────────────────────────────────────────
<?php if ($success_msg): ?>
document.addEventListener('DOMContentLoaded', function() {
    const successDiv = document.getElementById('success-alert');
    if (successDiv) {
        setTimeout(() => {
            successDiv.style.opacity = '0';
            setTimeout(() => {
                successDiv.style.display = 'none';
            }, 800); // match transition duration
        }, 5000);
    }
});
<?php endif; ?>

// ────────────────────────────────────────────────
// Rest of your JavaScript (unchanged)
// ────────────────────────────────────────────────
const menuItems = <?= json_encode($menu_items) ?>;
const stock = <?= json_encode($stock) ?>;
const categories = <?= json_encode($categories) ?>;

let selectedItemsArray = [];
const STORAGE_KEY = 'superadmin_order_temp';

const grid = document.getElementById('menuGrid');
const placeholder = document.getElementById('placeholder');
const hiddenFieldsContainer = document.getElementById('hiddenFieldsContainer');
const tableInput = document.getElementById('table_identifier');

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function saveOrderData() {
    const saveData = {
        table: tableInput.value.trim(),
        items: selectedItemsArray
    };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(saveData));
}

function loadOrderData() {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved) {
        try {
            const data = JSON.parse(saved);
            tableInput.value = data.table || '';
            selectedItemsArray = data.items || [];
            renderHiddenFields();
        } catch (e) {
            selectedItemsArray = [];
        }
    }
}

function clearOrderData() {
    localStorage.removeItem(STORAGE_KEY);
    selectedItemsArray = [];
    tableInput.value = '';
    renderHiddenFields();
    document.querySelectorAll('.form-check-input').forEach(cb => cb.checked = false);
    document.querySelectorAll('.item-details').forEach(el => el.style.display = 'none');
}

function renderHiddenFields() {
    hiddenFieldsContainer.innerHTML = '';
    selectedItemsArray.forEach(item => {
        const checkbox = document.createElement('input');
        checkbox.type = 'hidden';
        checkbox.name = 'selected_items[]';
        checkbox.value = item.id;

        const qtyInput = document.createElement('input');
        qtyInput.type = 'hidden';
        qtyInput.name = `quantities[${item.id}]`;
        qtyInput.value = item.qty;

        const notesInput = document.createElement('input');
        notesInput.type = 'hidden';
        notesInput.name = `notes[${item.id}]`;
        notesInput.value = item.notes;

        hiddenFieldsContainer.appendChild(checkbox);
        hiddenFieldsContainer.appendChild(qtyInput);
        hiddenFieldsContainer.appendChild(notesInput);
    });
}

function addToSelected(itemId, qty = 1, notes = '') {
    const existingIndex = selectedItemsArray.findIndex(i => i.id == itemId);
    if (existingIndex !== -1) {
        selectedItemsArray[existingIndex] = { id: itemId, qty, notes };
    } else {
        selectedItemsArray.push({ id: itemId, qty, notes });
    }
    saveOrderData();
    renderHiddenFields();
}

function removeFromSelected(itemId) {
    selectedItemsArray = selectedItemsArray.filter(i => i.id != itemId);
    saveOrderData();
    renderHiddenFields();
}

function renderCategory(category) {
    grid.innerHTML = '';
    placeholder.style.display = 'none';

    const filtered = menuItems.filter(item => (item.category || '').trim() === category);
    if (filtered.length === 0) {
        grid.innerHTML = '<div class="col-12 text-center py-5 text-muted">No items in this category</div>';
        return;
    }

    filtered.forEach(item => {
        const selected = selectedItemsArray.find(i => i.id == item.id) || { qty: 1, notes: '' };
        const checked = selectedItemsArray.some(i => i.id == item.id);
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
                            <input type="number" class="form-control qty-input" value="${selected.qty}" min="1" data-item-id="${item.id}">
                        </div>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">Notes</span>
                            <input type="text" class="form-control notes-input" value="${escapeHtml(selected.notes)}" placeholder="e.g. less spicy" data-item-id="${item.id}">
                        </div>
                    </div>
                </div>
            </div>
        `;
        grid.appendChild(div);
    });
}

// ────────────────────────────────────────────────
// Event listeners (unchanged)
// ────────────────────────────────────────────────
grid.addEventListener('change', function(e) {
    if (e.target.type === 'checkbox') {
        const itemId = e.target.value;
        const details = document.getElementById('details_' + itemId);
        const qtyInput = details?.querySelector('.qty-input');
        const notesInput = details?.querySelector('.notes-input');

        if (e.target.checked) {
            if (details) details.style.display = 'block';
            const qty = qtyInput ? parseInt(qtyInput.value) || 1 : 1;
            const notes = notesInput ? notesInput.value.trim() : '';
            addToSelected(itemId, qty, notes);
        } else {
            if (details) details.style.display = 'none';
            removeFromSelected(itemId);
        }
    }
});

grid.addEventListener('input', function(e) {
    if (e.target.classList.contains('qty-input') || e.target.classList.contains('notes-input')) {
        const itemId = e.target.dataset.itemId;
        if (itemId && selectedItemsArray.some(i => i.id == itemId)) {
            const card = e.target.closest('.card');
            const qty = parseInt(card.querySelector('.qty-input')?.value) || 1;
            const notes = card.querySelector('.notes-input')?.value.trim() || '';
            addToSelected(itemId, qty, notes);
        }
    }
});

document.querySelectorAll('#categoryTabs button').forEach(tab => {
    tab.addEventListener('click', function () {
        document.querySelectorAll('#categoryTabs button').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        renderCategory(this.dataset.category);
        document.getElementById('searchInput').value = '';
    });
});

document.getElementById('searchInput').addEventListener('input', function () {
    const term = this.value.toLowerCase().trim();
    grid.querySelectorAll('.col-6, .col-md-4, .col-lg-3').forEach(card => {
        const text = card.textContent.toLowerCase();
        card.style.display = text.includes(term) ? 'block' : 'none';
    });
});

function showCenterToast(message) {
    document.getElementById('toastMessage').textContent = message;
    document.getElementById('centerToast').style.display = 'block';
    setTimeout(() => document.getElementById('centerToast').style.display = 'none', 4000);
}

document.getElementById('reviewOrderBtn').addEventListener('click', function () {
    const table = tableInput.value.trim();
    if (selectedItemsArray.length === 0) {
        showCenterToast('Please select at least one item.');
        return;
    }
    if (!table) {
        showCenterToast('Please enter Table Number or Customer Name / Phone.');
        return;
    }

    let html = `<p class="fw-bold fs-5 mb-3">Order for: <span class="text-primary">${escapeHtml(table)}</span></p>`;
    html += '<ul class="list-group list-group-flush">';
    let total = 0;

    selectedItemsArray.forEach(sel => {
        const item = menuItems.find(i => i.id == sel.id);
        if (!item) return;
        const sub = item.price * sel.qty;
        total += sub;
        html += `
            <li class="list-group-item d-flex justify-content-between">
                <div>
                    <div class="fw-bold">${escapeHtml(item.item_name)} × ${sel.qty}</div>
                    ${sel.notes ? `<small class="text-muted">${escapeHtml(sel.notes)}</small>` : ''}
                </div>
                <span class="badge bg-primary rounded-pill">Rs ${sub.toFixed(2)}</span>
            </li>`;
    });

    html += `</ul><hr><div class="d-flex justify-content-between fs-5">
        <strong>Total Amount:</strong>
        <strong class="text-success">Rs ${total.toFixed(2)}</strong>
    </div>`;

    document.getElementById('orderSummary').innerHTML = html;
    new bootstrap.Modal(document.getElementById('confirmModal')).show();
});

document.getElementById('placeOrderBtn').addEventListener('click', () => {
    document.getElementById('orderForm').submit();
});

document.addEventListener('DOMContentLoaded', () => {
    loadOrderData();

    if (categories.length > 0) {
        renderCategory(categories[0]);
        document.querySelector('#categoryTabs button')?.classList.add('active');
    }

    tableInput.addEventListener('input', saveOrderData);

    <?php if ($success_msg): ?>
        clearOrderData();
    <?php endif; ?>
});

document.querySelector('a.btn-secondary[href="view_branch.php"]')?.addEventListener('click', () => {
    localStorage.removeItem(STORAGE_KEY);
});
</script>

<?php include '../Common/footer.php'; ?>
</body>
</html>