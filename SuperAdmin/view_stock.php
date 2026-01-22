<?php
session_start();
require '../vendor/autoload.php';
require '../Common/connection.php';
require '../Common/nepali_date.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['current_restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

function bs_to_ad(string $bsDate): string
{
    [$year, $month, $day] = explode('-', $bsDate);
    $nepali = new Nilambar\NepaliDate\NepaliDate();
    $ad     = $nepali->convertBsToAd((int)$year, (int)$month, (int)$day);
    return sprintf('%04d-%02d-%02d', $ad['year'], $ad['month'], $ad['day']);
}

$restaurant_id = (int)$_SESSION['current_restaurant_id'];
$user_id       = (int)$_SESSION['user_id'];
$message       = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$today_ad         = date('Y-m-d');
$today_bs         = ad_to_bs($today_ad);
$one_month_ago_ad = date('Y-m-d', strtotime('-1 month'));
$one_month_ago_bs = ad_to_bs($one_month_ago_ad);

$start_date = $_POST['start_date'] ?? $_SESSION['ps_start'] ?? $one_month_ago_bs;
$end_date   = $_POST['end_date']   ?? $_SESSION['ps_end']   ?? $today_bs;

$_SESSION['ps_start'] = $start_date;
$_SESSION['ps_end']   = $end_date;

if (isset($_POST['clear_filter'])) {
    unset($_SESSION['ps_start'], $_SESSION['ps_end']);
    header("Location: view_stock.php");
    exit;
}

$conditions = "restaurant_id = ?";
$params     = [$restaurant_id];
$types      = "i";

if ($start_date && $end_date) {
    $conditions .= " AND transaction_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types   .= "ss";
}

$stmt = $conn->prepare("SELECT p.*, 
    (SELECT COUNT(*) FROM purchase_history ph WHERE ph.purchase_id = p.id) AS edit_count
    FROM purchase p 
    WHERE $conditions 
    ORDER BY transaction_date DESC, id DESC");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$grand_total = 0;
$purchases   = [];
while ($p = $result->fetch_assoc()) {
    $grand_total += $p['net_total'];
    $purchases[] = $p;
}

// ====================== EDIT PURCHASE (WITH NEGATIVE STOCK PREVENTION) ======================
if (isset($_POST['action']) && $_POST['action'] === 'save_purchase_edit') {
    $conn->autocommit(false);
    try {
        $purchase_id = (int)$_POST['purchase_id'];
        $edit_reason = trim($_POST['edit_reason'] ?? '');
        if ($edit_reason === '') throw new Exception("Edit reason is required.");

        $stmt = $conn->prepare("SELECT * FROM purchase WHERE id = ? AND restaurant_id = ?");
        $stmt->bind_param("ii", $purchase_id, $restaurant_id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$current) throw new Exception("Purchase not found.");

        $old_items = json_decode($current['items_json'] ?? '[]', true) ?? [];
        $now_bs    = nepali_date_time();

        // New values
        $bill_no             = trim($_POST['bill_no'] ?? '');
        $company_name        = trim($_POST['company_name'] ?? '');
        $vat_no              = trim($_POST['vat_no'] ?? '');
        $address             = trim($_POST['address'] ?? '');
        $transaction_date_bs = trim($_POST['transaction_date'] ?? '');
        $discount            = floatval($_POST['discount'] ?? 0);
        $vat_percent         = ($_POST['vat_option'] ?? '0') === '13' ? 13.00 : 0.00;

        if (!$bill_no || !$company_name || !$transaction_date_bs) {
            throw new Exception("Bill No, Company Name and Date are required.");
        }

        $fiscal_year = get_fiscal_year($transaction_date_bs);

        // DUPLICATE BILL CHECK (Same as stock_management.php)
        $chk = $conn->prepare("SELECT id FROM purchase 
            WHERE restaurant_id = ? 
              AND company_name = ? 
              AND bill_no = ? 
              AND fiscal_year = ? 
              AND id != ?");
        $chk->bind_param("isssi", $restaurant_id, $company_name, $bill_no, $fiscal_year, $purchase_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            throw new Exception("DUPLICATE_BILL|$bill_no|$company_name|$fiscal_year");
        }
        $chk->close();

        // Save to history
        $hist = $conn->prepare("INSERT INTO purchase_history 
            (purchase_id, restaurant_id, edited_by, edited_at, edit_reason, bill_no, company_name, vat_no, address,
             transaction_date, fiscal_year, items_json, total_amount, discount, taxable_amount, vat_percent, net_total)
            SELECT id, restaurant_id, ?, ?, ?, bill_no, company_name, vat_no, address,
                   transaction_date, fiscal_year, items_json, total_amount, discount, taxable_amount, vat_percent, net_total
            FROM purchase WHERE id = ? AND restaurant_id = ?");
        $hist->bind_param("issii", $user_id, $now_bs, $edit_reason, $purchase_id, $restaurant_id);
        $hist->execute();
        $hist->close();

        // Process items
        $new_items    = [];
        $total_amount = 0;
        foreach (($_POST['item_name'] ?? []) as $i => $name) {
            $name = trim($name);
            $hs   = trim($_POST['hs_code_item'][$i] ?? '');
            $qty  = floatval($_POST['quantity'][$i] ?? 0);
            $rate = floatval($_POST['rate'][$i] ?? 0);
            $unit = trim($_POST['unit'][$i] ?? '');
            if ($name && $qty > 0 && $rate > 0 && $unit) {
                $line = $qty * $rate;
                $total_amount += $line;
                $new_items[] = [
                    'name'     => $name,
                    'hs_code'  => $hs,
                    'quantity' => $qty,
                    'rate'     => $rate,
                    'unit'     => $unit,
                    'total'    => $line
                ];
            }
        }
        if (empty($new_items)) throw new Exception("At least one item required.");

        $taxable   = max($total_amount - $discount, 0);
        $net_total = $vat_percent == 13 ? round($taxable * 1.13, 2) : $taxable;
        $items_json = json_encode($new_items, JSON_UNESCAPED_UNICODE);

        // Update stock WITH NEGATIVE STOCK PREVENTION
        $stock_sql = "INSERT INTO stock_inventory (restaurant_id, stock_name, quantity, unit, updated_at)
                      VALUES (?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE 
                          quantity = GREATEST(quantity + VALUES(quantity), 0),
                          unit = VALUES(unit),
                          updated_at = VALUES(updated_at)";
        $st = $conn->prepare($stock_sql);

        // FIRST: Remove old items (check for negative stock)
        foreach ($old_items as $it) {
            $name = $it['name'];
            $qty = -$it['quantity'];
            $unit = $it['unit'];

            // CHECK CURRENT STOCK BEFORE REMOVING
            $check_stmt = $conn->prepare("SELECT quantity FROM stock_inventory WHERE restaurant_id = ? AND stock_name = ?");
            $check_stmt->bind_param("is", $restaurant_id, $name);
            $check_stmt->execute();
            $current_stock = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            $current_qty = $current_stock ? floatval($current_stock['quantity']) : 0;
            if ($current_qty < abs($qty)) {
                throw new Exception("Cannot edit: Insufficient stock for '$name'. Current: " . number_format($current_qty, 3) . ", Required to remove: " . number_format(abs($qty), 3));
            }

            $st->bind_param("isdss", $restaurant_id, $name, $qty, $unit, $now_bs);
            $st->execute();
        }

        // THEN: Add new items
        foreach ($new_items as $it) {
            $st->bind_param("isdss", $restaurant_id, $it['name'], $it['quantity'], $it['unit'], $now_bs);
            $st->execute();
        }
        $st->close();

        // Update purchase
        $upd = $conn->prepare("UPDATE purchase SET 
            bill_no = ?, company_name = ?, vat_no = ?, address = ?, transaction_date = ?, fiscal_year = ?,
            items_json = ?, total_amount = ?, discount = ?, taxable_amount = ?, vat_percent = ?, net_total = ?, created_at = ?
            WHERE id = ? AND restaurant_id = ?");

        $upd->bind_param(
            "sssssssdddddsii",
            $bill_no, $company_name, $vat_no, $address, $transaction_date_bs, $fiscal_year,
            $items_json, $total_amount, $discount, $taxable, $vat_percent, $net_total, $now_bs,
            $purchase_id, $restaurant_id
        );
        $upd->execute();
        $upd->close();

        $conn->commit();
        $_SESSION['message'] = "Purchase updated successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $msg = $e->getMessage();
        if (str_starts_with($msg, 'DUPLICATE_BILL|')) {
            [, $b, $c, $f] = explode('|', $msg, 4);
            $_SESSION['message'] = "<div class='alert alert-warning'><strong>Duplicate Bill!</strong> Bill No <strong>" . htmlspecialchars($b) . "</strong> from <strong>" . htmlspecialchars($c) . "</strong> already exists in fiscal year <strong>$f</strong>.</div>";
        } else {
            $_SESSION['message'] = "<div class='alert alert-danger'>Error: " . htmlspecialchars($msg) . "</div>";
        }
    }
    header("Location: view_stock.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>View Stock Purchases</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); min-height: 100vh; }
        .card { box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .total-row { background: #fff3cd !important; font-weight: bold; font-size: 1.2rem; }
        .badge-edit { background: #ffc107; color: black; }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h3 class="text-center text-dark fw-bold mb-4">Stock Purchase Entries</h3>

    <?php if ($message): ?>
        <div class="alert <?= strpos($message,'success')!==false ? 'alert-success' : 'alert-danger' ?> alert-dismissible fade show">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="POST" class="row g-3 align-items-end mb-3">
                <div class="col-md-4">
                    <label>Start Date (BS)</label>
                    <input type="text" name="start_date" class="form-control" value="<?=htmlspecialchars($start_date)?>" placeholder="2082-01-01">
                </div>
                <div class="col-md-4">
                    <label>End Date (BS)</label>
                    <input type="text" name="end_date" class="form-control" value="<?=htmlspecialchars($end_date)?>" placeholder="2082-12-30">
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">Filter</button>
                    <button type="submit" name="clear_filter" value="1" class="btn btn-outline-secondary">Clear</button>
                </div>
            </form>

            <?php if (!empty($purchases)): ?>
            <div class="text-end mb-3">
                <a href="export_stock_purchases.php" class="btn btn-success btn-lg" target="_blank">
                    Export to Excel
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-primary">
                        <tr>
                            <th>#</th><th>Bill No</th><th>Company</th><th>Date (BS)</th>
                            <th class="text-end">Net Total</th><th>Edits</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($purchases)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No purchase entries found</td></tr>
                        <?php else: $i=1; foreach($purchases as $p): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($p['bill_no']) ?></td>
                            <td><?= htmlspecialchars($p['company_name']) ?></td>
                            <td><?= $p['transaction_date'] ?></td>
                            <td class="text-end fw-bold text-success"><?= number_format($p['net_total'], 2) ?></td>
                            <td class="text-center">
                                <?php if ($p['edit_count'] > 0): ?>
                                    <span class="badge badge-edit"><?= $p['edit_count'] ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-warning btn-sm" onclick="openEditModal(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">Edit</button>
                                <button class="btn btn-info btn-sm text-white" onclick="openHistoryModal(<?= $p['id'] ?>)">History</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="4" class="text-end pe-4">GRAND TOTAL:</td>
                            <td class="text-end text-success">Rs <?= number_format($grand_total, 2) ?></td>
                            <td colspan="2"></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                <a href="view_branch.php" class="btn btn-dark">Back</a>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">Edit Purchase #<span id="editId"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="editForm"></div>
        </div>
    </div>
</div>

<!-- History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white d-flex justify-content-between">
                <h5 class="modal-title">Purchase History #<span id="histId"></span></h5>
                <button type="button" class="btn btn-light btn-sm me-2" onclick="exportToExcel()">Export History</button>
            </div>
            <div class="modal-body" id="histBody"><div class="text-center p-4"><div class="spinner-border text-info"></div></div></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
function e(t) { const d = document.createElement('div'); d.textContent = t || ''; return d.innerHTML; }
let currentHistoryData = [];

function openEditModal(id, purchase) {
    document.getElementById('editId').textContent = id;
    let items = JSON.parse(purchase.items_json || '[]');

    let rows = '';
    items.forEach(it => {
        rows += `<tr>
            <td><input type="text" name="item_name[]" class="form-control form-control-sm" value="${e(it.name)}" required></td>
            <td><input type="text" name="hs_code_item[]" class="form-control form-control-sm" value="${e(it.hs_code ?? '')}"></td>
            <td><input type="number" step="0.001" name="quantity[]" class="form-control form-control-sm qty" value="${it.quantity}" required></td>
            <td><input type="number" step="0.01" name="rate[]" class="form-control form-control-sm rate" value="${it.rate}" required></td>
            <td><input type="text" name="unit[]" class="form-control form-control-sm" value="${e(it.unit)}" required></td>
            <td class="total text-end fw-bold">0.00</td>
            <td><button type="button" class="btn btn-danger btn-sm" onclick="this.closest('tr').remove(); calcTotal()">Remove</button></td>
        </tr>`;
    });

    document.getElementById('editForm').innerHTML = `
        <form method="post">
            <input type="hidden" name="action" value="save_purchase_edit">
            <input type="hidden" name="purchase_id" value="${id}">
            <div class="row g-3 mb-3">
                <div class="col-md-3"><label>Bill No</label><input type="text" name="bill_no" class="form-control" value="${e(purchase.bill_no)}" required></div>
                <div class="col-md-5"><label>Company Name</label><input type="text" name="company_name" class="form-control" value="${e(purchase.company_name)}" required></div>
                <div class="col-md-4"><label>Date (BS)</label><input type="text" name="transaction_date" class="form-control" value="${purchase.transaction_date}" required></div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label>VAT/PAN No</label><input type="text" name="vat_no" class="form-control" value="${e(purchase.vat_no ?? '')}"></div>
                <div class="col-md-6"><label>Address</label><input type="text" name="address" class="form-control" value="${e(purchase.address ?? '')}"></div>
            </div>
            <hr>
            <div class="mb-3">
                <label class="text-danger fw-bold">Edit Reason (Required)</label>
                <textarea name="edit_reason" class="form-control" rows="2" required placeholder="Why are you editing this purchase?"></textarea>
            </div>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered">
                    <thead class="table-light"><tr><th>Item</th><th>HS Code</th><th>Qty</th><th>Rate</th><th>Unit</th><th>Total</th><th></th></tr></thead>
                    <tbody id="itemRows">${rows || '<tr><td colspan="7" class="text-center">No items</td></tr>'}</tbody>
                </table>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm mb-3" onclick="addRow()">+ Add Item</button>
            <div class="row g-3 mb-3">
                <div class="col-md-4"><label>Discount</label><input type="number" step="0.01" id="discount" name="discount" class="form-control" value="${purchase.discount ?? 0}"></div>
                <div class="col-md-4"><label>VAT</label>
                    <select id="vat_option" name="vat_option" class="form-select">
                        <option value="0" ${purchase.vat_percent == 0 ? 'selected' : ''}>0%</option>
                        <option value="13" ${purchase.vat_percent == 13 ? 'selected' : ''}>13%</option>
                    </select>
                </div>
                <div class="col-md-4 text-end">
                    <h5>Net Total: <strong id="netTotal" class="text-success">0.00</strong> NPR</h5>
                </div>
            </div>
            <div class="text-center">
                <button type="submit" class="btn btn-success btn-lg">Save Changes</button>
            </div>
        </form>`;

    document.querySelectorAll('.qty, .rate, #discount, #vat_option').forEach(el => {
        el.addEventListener('input', calcTotal);
        el.addEventListener('change', calcTotal);
    });
    calcTotal();
    new bootstrap.Modal('#editModal').show();
}

function addRow() {
    const tbody = document.getElementById('itemRows');
    const row   = tbody.insertRow();
    row.innerHTML = `
        <td><input type="text" name="item_name[]" class="form-control form-control-sm" required></td>
        <td><input type="text" name="hs_code_item[]" class="form-control form-control-sm"></td>
        <td><input type="number" step="0.001" name="quantity[]" class="form-control form-control-sm qty" required></td>
        <td><input type="number" step="0.01" name="rate[]" class="form-control form-control-sm rate" required></td>
        <td><input type="text" name="unit[]" class="form-control form-control-sm" required></td>
        <td class="total text-end fw-bold">0.00</td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="this.closest('tr').remove(); calcTotal()">Remove</button></td>`;
    row.querySelectorAll('.qty, .rate').forEach(el => el.addEventListener('input', calcTotal));
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('#itemRows tr').forEach(row => {
        const qty  = parseFloat(row.querySelector('.qty')?.value) || 0;
        const rate = parseFloat(row.querySelector('.rate')?.value) || 0;
        const amt  = qty * rate;
        row.querySelector('.total').textContent = amt.toFixed(2);
        total += amt;
    });
    const discount = parseFloat(document.getElementById('discount')?.value) || 0;
    const taxable  = Math.max(total - discount, 0);
    const vat      = document.getElementById('vat_option')?.value === '13' ? 13 : 0;
    const net      = vat === 13 ? taxable * 1.13 : taxable;
    document.getElementById('netTotal').textContent = net.toFixed(2);
}

function openHistoryModal(id) {
    document.getElementById('histId').textContent = id;
    const body = document.getElementById('histBody');
    body.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-info"></div></div>';

    fetch(`get_purchase_history.php?purchase_id=${id}&restaurant_id=<?= $restaurant_id ?>`)
        .then(r => r.json())
        .then(data => {
            currentHistoryData = data || [];
            if (!data || data.length === 0) {
                body.innerHTML = '<p class="text-center text-muted fs-5">No edit history available yet.</p>';
                return;
            }
            let html = '';
            data.forEach(h => {
                let items = JSON.parse(h.items_json || '[]');
                let tbl = '<table class="table table-sm table-bordered mb-3"><thead class="table-light"><tr><th>Item</th><th>Qty</th><th>Rate</th><th>Unit</th><th>Total</th></tr></thead><tbody>';
                let total = 0;
                items.forEach(i => {
                    const amt = (i.quantity || 0) * (i.rate || 0);
                    total += amt;
                    tbl += `<tr><td>${e(i.name)}</td><td class="text-end">${parseFloat(i.quantity || 0).toFixed(3)}</td><td class="text-end">${parseFloat(i.rate || 0).toFixed(2)}</td><td>${e(i.unit)}</td><td class="text-end fw-bold">${amt.toFixed(2)}</td></tr>`;
                });
                tbl += `</tbody></table><div class="text-end mb-3"><h5>Net Total: <strong class="text-success">${parseFloat(h.net_total || 0).toFixed(2)} NPR</strong></h5></div>`;

                html += `<div class="card mb-4 shadow-sm border-primary">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between">
                            <div><strong>Edited by:</strong> ${e(h.editor_name || 'Unknown')}</div>
                            <div><strong>On:</strong> ${e(h.edited_at)}</div>
                        </div>
                        <div class="mt-2"><em>Reason: ${e(h.edit_reason)}</em></div>
                    </div>
                    <div class="card-body">
                        <p class="mb-3"><strong>Company:</strong> ${e(h.company_name)} | <strong>Date:</strong> ${h.transaction_date}</p>
                        ${tbl}
                    </div>
                </div>`;
            });
            body.innerHTML = html;
        })
        .catch(() => body.innerHTML = '<p class="text-danger text-center">Failed to load history.</p>');

    new bootstrap.Modal('#historyModal').show();
}

function exportToExcel() {
    if (!currentHistoryData || currentHistoryData.length === 0) return alert("No history!");
    const wb = XLSX.utils.book_new();
    const ws_data = [["Edit Date","Editor","Reason","Company","Bill Date","Item","Qty","Rate","Unit","Amount"]];
    let gtotal = 0;
    currentHistoryData.forEach(h => {
        let items = JSON.parse(h.items_json || '[]');
        let billTotal = 0;
        items.forEach((it, i) => {
            const amt = (it.quantity || 0) * (it.rate || 0);
            billTotal += amt;
            if (i === 0) {
                ws_data.push([h.edited_at, h.editor_name || 'Unknown', h.edit_reason, h.company_name, h.transaction_date, it.name, it.quantity, it.rate, it.unit, amt.toFixed(2)]);
            } else {
                ws_data.push(["","","","", "", it.name, it.quantity, it.rate, it.unit, amt.toFixed(2)]);
            }
        });
        gtotal += billTotal;
        ws_data.push(["","","","","","BILL TOTAL","","","", billTotal.toFixed(2)]);
        ws_data.push([]);
    });
    ws_data.push(["","","","","","GRAND TOTAL","","","", gtotal.toFixed(2)]);
    const ws = XLSX.utils.aoa_to_sheet(ws_data);
    XLSX.utils.book_append_sheet(wb, ws, "History");
    XLSX.writeFile(wb, `Purchase_${document.getElementById('histId').textContent}_History.xlsx`);
}
</script>

<?php include '../Common/footer.php'; ?>
</body>
</html>