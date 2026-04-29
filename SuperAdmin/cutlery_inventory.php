<?php
// SuperAdmin/cutlery_inventory.php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin' || empty($_SESSION['current_restaurant_id'])) {
    header('Location: ../login.php');
    exit;
}

$restaurant_id = $_SESSION['current_restaurant_id'];
$success_msg = '';
$error_msg = '';
$user_id = $_SESSION['user_id'] ?? null;
$now = nepali_date_time();   // Full Nepali datetime

// ==================== HANDLE POST ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_stock'])) {
        $item_name = trim($_POST['item_name'] ?? '');
        $category  = trim($_POST['category'] ?? '');
        $qty       = (int)($_POST['quantity'] ?? 0);
        $price     = (float)($_POST['unit_price'] ?? 0);
        $remarks   = trim($_POST['remarks'] ?? '');

        if (empty($item_name)) {
            $error_msg = "Item name is required.";
        } elseif (empty($category)) {
            $error_msg = "Category is required.";
        } elseif ($qty <= 0) {
            $error_msg = "Quantity must be greater than 0.";
        } else {
            $check = $conn->prepare("SELECT id FROM cutlery_inventory WHERE restaurant_id = ? AND item_name = ?");
            $check->bind_param("is", $restaurant_id, $item_name);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();
            $check->close();

            if ($exists) {
                $stmt = $conn->prepare("UPDATE cutlery_inventory SET total_purchased = total_purchased + ?, current_stock = current_stock + ?, category = ?, unit_price = ?, last_updated_at = ? WHERE id = ?");
                $stmt->bind_param("iisdsi", $qty, $qty, $category, $price, $now, $exists['id']);
                $action = 'restocked';
            } else {
                $stmt = $conn->prepare("INSERT INTO cutlery_inventory (restaurant_id, item_name, category, total_purchased, current_stock, unit_price, last_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issidss", $restaurant_id, $item_name, $category, $qty, $qty, $price, $now);
                $action = 'added';
            }

            if ($stmt->execute()) {
                $cutlery_id = $exists ? $exists['id'] : $conn->insert_id;

                $stock_stmt = $conn->prepare("SELECT current_stock FROM cutlery_inventory WHERE id = ?");
                $stock_stmt->bind_param("i", $cutlery_id);
                $stock_stmt->execute();
                $stock_after = $stock_stmt->get_result()->fetch_assoc()['current_stock'] ?? 0;
                $stock_stmt->close();

                // Save price only for 'added' and 'restocked'
                $price_to_save = ($action === 'added' || $action === 'restocked') ? $price : NULL;

                $hist = $conn->prepare("INSERT INTO cutlery_history 
                    (cutlery_id, restaurant_id, action, quantity, remarks, changed_by, created_at, stock_after, price) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $hist->bind_param("iisissidi", $cutlery_id, $restaurant_id, $action, $qty, $remarks, $user_id, $now, $stock_after, $price_to_save);
                $hist->execute();
                $hist->close();

                $success_msg = $exists ? "Stock added successfully!" : "New item added successfully!";
            } else {
                $error_msg = "Failed to save data.";
            }
            $stmt->close();
        }
    }

    // Record Broken or Missing
    elseif (isset($_POST['record_loss'])) {
        $cutlery_id = (int)($_POST['cutlery_id'] ?? 0);
        $type = $_POST['type'] ?? '';
        $qty = (int)($_POST['loss_quantity'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');

        if ($cutlery_id > 0 && $qty > 0 && in_array($type, ['broken', 'missing'])) {
            $stmt = $conn->prepare("UPDATE cutlery_inventory SET current_stock = GREATEST(current_stock - ?, 0), {$type}_qty = {$type}_qty + ?, last_updated_at = ? WHERE id = ? AND restaurant_id = ?");
            $stmt->bind_param("iissi", $qty, $qty, $now, $cutlery_id, $restaurant_id);

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $stock_stmt = $conn->prepare("SELECT current_stock FROM cutlery_inventory WHERE id = ?");
                $stock_stmt->bind_param("i", $cutlery_id);
                $stock_stmt->execute();
                $stock_after = $stock_stmt->get_result()->fetch_assoc()['current_stock'] ?? 0;
                $stock_stmt->close();

                $hist = $conn->prepare("INSERT INTO cutlery_history 
                    (cutlery_id, restaurant_id, action, quantity, remarks, changed_by, created_at, stock_after, price) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)");
                $hist->bind_param("iisissii", $cutlery_id, $restaurant_id, $type, $qty, $remarks, $user_id, $now, $stock_after);
                $hist->execute();
                $hist->close();

                $success_msg = ucfirst($type) . " recorded successfully!";
            }
            $stmt->close();
        }
    }
}

// Fetch Inventory
$stmt = $conn->prepare("SELECT * FROM cutlery_inventory WHERE restaurant_id = ? ORDER BY category ASC, item_name ASC");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$inventory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cutlery Inventory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .badge-qty { font-size: 0.95rem; padding: 0.5em 0.8em; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-success"><i class="bi bi-silverware me-2"></i>Cutlery Inventory</h3>
        <div>
            <a href="view_branch.php" class="btn btn-secondary me-2">Back</a>
            <button onclick="exportCurrentToExcel()" class="btn btn-success">Export All</button>
        </div>
    </div>

    <?php if($success_msg): ?><div class="alert alert-success"><?= $success_msg ?></div><?php endif; ?>
    <?php if($error_msg): ?><div class="alert alert-danger"><?= $error_msg ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5><i class="bi bi-plus-circle"></i> Add New Item / Stock</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label>Item Name</label>
                            <input type="text" name="item_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Category</label>
                            <input type="text" name="category" class="form-control" list="catList" required>
                            <datalist id="catList">
                                <?php foreach(array_unique(array_column($inventory,'category')) as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label>Quantity</label>
                                <input type="number" name="quantity" class="form-control" min="1" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label>Unit Price (Rs)</label>
                                <input type="number" step="0.01" name="unit_price" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Remarks</label>
                            <input type="text" name="remarks" class="form-control" placeholder="Optional">
                        </div>
                        <button type="submit" name="add_stock" class="btn btn-success w-100">Add Stock</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="inventoryTable">
                        <thead class="table-light">
                            <tr>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th class="text-center">Current Stock</th>
                                <th class="text-center">Broken</th>
                                <th class="text-center">Missing</th>
                                <th class="text-center">Total Bought</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventory as $row): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['item_name']) ?></strong></td>
                                <td><?= htmlspecialchars($row['category']) ?></td>
                                <td class="text-center fw-bold text-success"><?= $row['current_stock'] ?></td>
                                <td class="text-center text-danger"><?= $row['broken_qty'] ?? 0 ?></td>
                                <td class="text-center text-warning"><?= $row['missing_qty'] ?? 0 ?></td>
                                <td class="text-center"><?= $row['total_purchased'] ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-primary" onclick="showAddStockModal(<?= $row['id'] ?>, '<?= addslashes($row['item_name']) ?>', '<?= addslashes($row['category']) ?>')">Add Stock</button>
                                    <button class="btn btn-sm btn-danger" onclick="showLossModal(<?= $row['id'] ?>, 'broken')">Broken</button>
                                    <button class="btn btn-sm btn-secondary" onclick="showLossModal(<?= $row['id'] ?>, 'missing')">Missing</button>
                                    <a href="cutlery_history.php?cutlery_id=<?= $row['id'] ?>&item=<?= urlencode($row['item_name']) ?>" class="btn btn-sm btn-info">History</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Stock Modal -->
<div class="modal fade" id="addStockModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Stock - <span id="modalItemName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="item_name" id="stock_item_name">
                    <input type="hidden" name="category" id="stock_category">

                    <div class="mb-3">
                        <label>Quantity to Add</label>
                        <input type="number" name="quantity" class="form-control" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label>Unit Price (Rs)</label>
                        <input type="number" step="0.01" name="unit_price" class="form-control" value="0">
                    </div>
                    <div class="mb-3">
                        <label>Remarks</label>
                        <input type="text" name="remarks" class="form-control" placeholder="Optional">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_stock" class="btn btn-success">Add Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Loss Modal -->
<div class="modal fade" id="lossModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="lossTitle">Record Loss</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="cutlery_id" id="loss_cutlery_id">
                    <input type="hidden" name="type" id="loss_type">
                    <div class="mb-3">
                        <label>Quantity</label>
                        <input type="number" name="loss_quantity" id="loss_quantity" class="form-control" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label>Remarks</label>
                        <textarea name="remarks" id="loss_remarks" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="record_loss" class="btn btn-danger">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showAddStockModal(id, name, category) {
    document.getElementById('stock_item_name').value = name;
    document.getElementById('stock_category').value = category;
    document.getElementById('modalItemName').textContent = name;
    new bootstrap.Modal(document.getElementById('addStockModal')).show();
}

function showLossModal(id, type) {
    document.getElementById('loss_cutlery_id').value = id;
    document.getElementById('loss_type').value = type;
    document.getElementById('lossTitle').textContent = type === 'broken' ? 'Record Broken Items' : 'Record Missing Items';
    new bootstrap.Modal(document.getElementById('lossModal')).show();
}

function exportCurrentToExcel() {
    const table = document.getElementById('inventoryTable');
    let csv = "Item Name,Category,Current Stock,Broken,Missing,Total Bought\n";
    table.querySelectorAll('tbody tr').forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length) {
            csv += `"${cells[0].innerText.replace(/"/g,'""')}","${cells[1].innerText}",${cells[2].innerText},${cells[3].innerText},${cells[4].innerText},${cells[5].innerText}\n`;
        }
    });
    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'cutlery_inventory.csv';
    link.click();
}
</script>

</body>
</html>