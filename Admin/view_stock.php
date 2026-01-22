<?php
session_start();
require '../vendor/autoload.php';
require '../Common/connection.php';
require '../Common/nepali_date.php';

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

$restaurant_id = (int)$_SESSION['restaurant_id'];
$user_id       = (int)$_SESSION['user_id'] ?? 0;
$message       = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// Load restaurant name
$restaurant_name = 'Restaurant';
$stmt = $conn->prepare("SELECT name FROM restaurants WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $restaurant_name = $row['name'];
}
$stmt->close();

function bs_to_ad(string $bsDate): string {
    [$year,$month,$day] = explode('-', $bsDate);
    $nepali = new Nilambar\NepaliDate\NepaliDate();
    $ad = $nepali->convertBsToAd((int)$year,(int)$month,(int)$day);
    return sprintf('%04d-%02d-%02d',$ad['year'],$ad['month'],$ad['day']);
}

$today_bs = ad_to_bs(date('Y-m-d'));
$one_month_ago_bs = ad_to_bs(date('Y-m-d', strtotime('-1 month')));

$start_date = $_POST['start_date'] ?? $_SESSION['ps_start'] ?? $one_month_ago_bs;
$end_date   = $_POST['end_date'] ?? $_SESSION['ps_end'] ?? $today_bs;

$_SESSION['ps_start'] = $start_date;
$_SESSION['ps_end'] = $end_date;

if (isset($_POST['clear_filter'])) {
    unset($_SESSION['ps_start'], $_SESSION['ps_end']);
    header("Location: view_stock.php");
    exit;
}

$conditions = "restaurant_id = ?";
$params = [$restaurant_id];
$types = "i";

if ($start_date && $end_date) {
    $conditions .= " AND transaction_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}
$stmt = $conn->prepare("
    SELECT 
        p.*, 
        COALESCE(ph.edit_count, 0) AS edit_count,
        COALESCE(u.username, 'Unknown User') AS added_by_name
    FROM purchase p 
    LEFT JOIN users u ON p.added_by = u.id
    LEFT JOIN (
        SELECT purchase_id, COUNT(*) AS edit_count 
        FROM purchase_history 
        GROUP BY purchase_id
    ) ph ON ph.purchase_id = p.id
    WHERE p.restaurant_id = ?
    " . ($start_date && $end_date ? " AND p.transaction_date BETWEEN ? AND ? " : "") . "
    ORDER BY p.transaction_date DESC, p.id DESC
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$grand_total = 0;
$purchases = [];
while ($p = $result->fetch_assoc()) {
    $grand_total += $p['net_total'];
    $purchases[] = $p;
}
$stmt->close();

// ==================== DETAILED GROUPED EXCEL EXPORT WITH ORIGINAL CREATOR ====================
if (isset($_POST['export_excel'])) {
    $restaurant_name_clean = trim(preg_replace('/[^a-zA-Z0-9\s\-]/', '', $restaurant_name)) ?: 'Restaurant';
    $clean_start = str_replace('-', '', $start_date);
    $clean_end   = str_replace('-', '', $end_date);
    $filename = "Stock_Purchases_Detailed_{$restaurant_name_clean}_{$clean_start}_to_{$clean_end}.xlsx";

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Title & Info
    $sheet->setCellValue('A1', "STOCK PURCHASES DETAILED REGISTER");
    $sheet->mergeCells('A1:P1');
    $sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $sheet->setCellValue('A2', "Restaurant: {$restaurant_name}");
    $sheet->mergeCells('A2:H2');
    $sheet->getStyle('A2')->getFont()->setBold(true);

    $sheet->setCellValue('I2', "Period: {$start_date} to {$end_date} (BS)");
    $sheet->mergeCells('I2:P2');
    $sheet->getStyle('I2')->getFont()->setBold(true);

    // Headers (Row 4)
    $headers = [
        'Bill No', 'Company Name', 'Date (BS)', 'VAT/PAN No', 'Address', 
        'Added By', 'Added On', 'Item Name', 'HS Code', 'Quantity', 
        'Rate', 'Unit', 'Line Total', 'Discount', 'VAT %', 'Net Total'
    ];
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . '4', $h);
        $sheet->getStyle($col . '4')->getFont()->setBold(true);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }
    $sheet->getStyle('A4:P4')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFD9EAD3');

    $rowNum = 5;
    $overallGrandTotal = 0;

    foreach ($purchases as $purchase) {
        $items = json_decode($purchase['items_json'] ?? '[]', true) ?? [];
        if (empty($items)) continue; // Skip empty bills if needed

        $billTotalBeforeDiscount = 0;

        // === Header Row (only once per bill) ===
        $sheet->setCellValue('A' . $rowNum, $purchase['bill_no']);
        $sheet->setCellValue('B' . $rowNum, $purchase['company_name']);
        $sheet->setCellValue('C' . $rowNum, $purchase['transaction_date']);
        $sheet->setCellValue('D' . $rowNum, $purchase['vat_no'] ?? '');
        $sheet->setCellValue('E' . $rowNum, $purchase['address'] ?? '');
        $sheet->setCellValue('F' . $rowNum, $purchase['added_by_name']);
        $sheet->setCellValue('G' . $rowNum, $purchase['created_at']);

        $sheet->getStyle("A{$rowNum}:G{$rowNum}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowNum}:P{$rowNum}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF0F0F0');

        $rowNum++;

        // === Item Rows ===
        foreach ($items as $it) {
            $qty = (float)($it['quantity'] ?? 0);
            $rate = (float)($it['rate'] ?? 0);
            $lineTotal = $qty * $rate;
            $billTotalBeforeDiscount += $lineTotal;

            $sheet->setCellValue('H' . $rowNum, $it['name'] ?? 'Item');
            $sheet->setCellValue('I' . $rowNum, $it['hs_code'] ?? '');
            $sheet->setCellValue('J' . $rowNum, $qty);
            $sheet->setCellValue('K' . $rowNum, $rate);
            $sheet->setCellValue('L' . $rowNum, $it['unit'] ?? '');
            $sheet->setCellValue('M' . $rowNum, $lineTotal);

            $rowNum++;
        }

        // === Bill Totals Row ===
        $vatText = ($purchase['vat_percent'] ?? 0) == 13 ? '13%' : '0%';

        $sheet->setCellValue('M' . $rowNum, 'Bill Total (before discount):');
        $sheet->setCellValue('N' . $rowNum, $billTotalBeforeDiscount);
        $sheet->setCellValue('N' . ($rowNum + 1), $purchase['discount'] ?? 0);
        $sheet->setCellValue('O' . ($rowNum + 1), $vatText);
        $sheet->setCellValue('P' . ($rowNum + 1), $purchase['net_total']);

        $sheet->getStyle("M{$rowNum}:P" . ($rowNum + 1))->getFont()->setBold(true);
        $sheet->getStyle("A{$rowNum}:P" . ($rowNum + 1))->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFE599');

        $overallGrandTotal += $purchase['net_total'];

        $rowNum += 3; // Blank row + spacing after totals
    }

    // === Grand Total ===
    $sheet->setCellValue('O' . $rowNum, 'GRAND TOTAL:');
    $sheet->setCellValue('P' . $rowNum, $overallGrandTotal);
    $sheet->getStyle("O{$rowNum}:P{$rowNum}")->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle("A{$rowNum}:P{$rowNum}")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFD9EAD3');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . urlencode($filename) . '"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'save_purchase_edit') {
    $conn->autocommit(false);
    try {
        $purchase_id = (int)$_POST['purchase_id'];
        $edit_reason = trim($_POST['edit_reason'] ?? '');
        if (!$edit_reason) throw new Exception("Edit reason is required.");
        $stmt = $conn->prepare("SELECT * FROM purchase WHERE id=? AND restaurant_id=?");
        $stmt->bind_param("ii",$purchase_id,$restaurant_id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$current) throw new Exception("Purchase not found.");
        $old_items = json_decode($current['items_json'] ?? '[]', true) ?? [];
        $now_bs = nepali_date_time();
        $bill_no = trim($_POST['bill_no'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        $vat_no = trim($_POST['vat_no'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $trans_date = trim($_POST['transaction_date'] ?? '');
        $discount = floatval($_POST['discount'] ?? 0);
        $vat_percent = ($_POST['vat_option'] ?? '0')==='13'?13:0;
        if (!$bill_no || !$company_name || !$trans_date) throw new Exception("Bill No, Company, and Date are required.");
        $fiscal_year = get_fiscal_year($trans_date);
        $chk = $conn->prepare("SELECT id FROM purchase WHERE restaurant_id=? AND company_name=? AND bill_no=? AND fiscal_year=? AND id!=?");
        $chk->bind_param("isssi",$restaurant_id,$company_name,$bill_no,$fiscal_year,$purchase_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows>0) throw new Exception("Duplicate bill detected.");
        $chk->close();
        $hist = $conn->prepare("INSERT INTO purchase_history (purchase_id, restaurant_id, edited_by, edited_at, edit_reason, bill_no, company_name, vat_no, address, transaction_date, fiscal_year, items_json, total_amount, discount, taxable_amount, vat_percent, net_total) SELECT id, restaurant_id, ?, ?, ?, bill_no, company_name, vat_no, address, transaction_date, fiscal_year, items_json, total_amount, discount, taxable_amount, vat_percent, net_total FROM purchase WHERE id=? AND restaurant_id=?");
        $hist->bind_param("issii",$user_id,$now_bs,$edit_reason,$purchase_id,$restaurant_id);
        $hist->execute();
        $hist->close();
        $new_items = [];
        $total_amount = 0;
        foreach ($_POST['item_name'] as $i=>$name) {
            $name = trim($name);
            $hs   = trim($_POST['hs_code_item'][$i] ?? '');
            $qty  = floatval($_POST['quantity'][$i] ?? 0);
            $rate = floatval($_POST['rate'][$i] ?? 0);
            $unit = trim($_POST['unit'][$i] ?? '');
            if ($name && $qty>0 && $rate>0 && $unit) {
                $line = $qty*$rate;
                $total_amount += $line;
                $new_items[] = ['name'=>$name,'hs_code'=>$hs,'quantity'=>$qty,'rate'=>$rate,'unit'=>$unit,'total'=>$line];
            }
        }
        if (empty($new_items)) throw new Exception("At least one item required.");
        $taxable = max($total_amount-$discount,0);
        $net_total = $vat_percent===13 ? round($taxable*1.13,2) : $taxable;
        $items_json = json_encode($new_items,JSON_UNESCAPED_UNICODE);
        $st_sql = "INSERT INTO stock_inventory (restaurant_id, stock_name, quantity, unit, updated_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity=GREATEST(quantity+VALUES(quantity),0), unit=VALUES(unit), updated_at=VALUES(updated_at)";
        $st = $conn->prepare($st_sql);
        foreach ($old_items as $it) {
            $name=$it['name'];
            $qty=-$it['quantity'];
            $unit=$it['unit'];
            $chk = $conn->prepare("SELECT quantity FROM stock_inventory WHERE restaurant_id=? AND stock_name=?");
            $chk->bind_param("is",$restaurant_id,$name);
            $chk->execute();
            $cur_qty = $chk->get_result()->fetch_assoc()['quantity'] ?? 0;
            $chk->close();
            if ($cur_qty < abs($qty)) throw new Exception("Insufficient stock for $name.");
            $st->bind_param("isdss",$restaurant_id,$name,$qty,$unit,$now_bs);
            $st->execute();
        }
        foreach ($new_items as $it) {
            $st->bind_param("isdss",$restaurant_id,$it['name'],$it['quantity'],$it['unit'],$now_bs);
            $st->execute();
        }
        $st->close();
        $upd = $conn->prepare("UPDATE purchase SET bill_no=?, company_name=?, vat_no=?, address=?, transaction_date=?, fiscal_year=?, items_json=?, total_amount=?, discount=?, taxable_amount=?, vat_percent=?, net_total=?, created_at=? WHERE id=? AND restaurant_id=?");
        $upd->bind_param("sssssssdddddsii",$bill_no,$company_name,$vat_no,$address,$trans_date,$fiscal_year,$items_json,$total_amount,$discount,$taxable,$vat_percent,$net_total,$now_bs,$purchase_id,$restaurant_id);
        $upd->execute();
        $upd->close();
        $conn->commit();
        $_SESSION['message'] = "Purchase updated successfully!";
    } catch(Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "Error: ".$e->getMessage();
    }
    header("Location: view_stock.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin - View Stock Purchases</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body{background: linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;}
.card{border-radius:15px;box-shadow:0 10px 30px rgba(0,0,0,0.2);}
.table th{text-align:center;}
.items-list{max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:left;}
.items-list:hover{white-space:normal;overflow:visible;}
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand text-white fw-bold" href="dashboard.php">
            <?= htmlspecialchars($restaurant_name) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <div class="navbar-nav ms-auto">
                <a class="nav-link text-white" href="dashboard.php">Dashboard</a>
                <a class="nav-link text-white" href="../Common/logout.php">Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="container py-4">
    <h3 class="text-center mb-4 text-white fw-bold">📦 View Stock Purchases</h3>

    <div class="text-start mb-3">
        <a href="dashboard.php" class="btn btn-secondary">&larr; Back to Dashboard</a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Start Date (BS)</label>
                    <input type="text" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" placeholder="2082-01-01">
                </div>
                <div class="col-md-4">
                    <label class="form-label">End Date (BS)</label>
                    <input type="text" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" placeholder="2082-12-30">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary me-2">Filter</button>
                    <?php if ($start_date || $end_date): ?>
                        <button type="submit" name="clear_filter" value="1" class="btn btn-secondary me-2">Clear</button>
                    <?php endif; ?>
                    <button type="submit" name="export_excel" class="btn btn-success">Export Excel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle text-center">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Date (BS)</th>
                    <th>Company</th>
                    <th>Bill No</th>
                    <th>Items</th>
                    <th>Net Total (NPR)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($purchases)): ?>
                    <?php foreach ($purchases as $p): ?>
                        <?php
                        $items = json_decode($p['items_json'] ?? '[]', true) ?? [];
                        $item_list = [];
                        foreach ($items as $it) {
                            $name = $it['name'] ?? 'Item';
                            $qty = (float)($it['quantity'] ?? 0);
                            if ($qty > 1) $name .= " ×{$qty}";
                            $item_list[] = $name;
                        }
                        $items_display = implode(', ', $item_list) ?: '—';
                        ?>
                        <tr>
                            <td><?= $p['id'] ?></td>
                            <td><?= htmlspecialchars($p['transaction_date']) ?></td>
                            <td><?= htmlspecialchars($p['company_name']) ?></td>
                            <td><?= htmlspecialchars($p['bill_no']) ?></td>
                            <td class="items-list"><?= htmlspecialchars($items_display) ?></td>
                            <td><strong><?= number_format($p['net_total'], 2) ?></strong></td>
                            <td>
                                <button class="btn btn-info btn-sm" onclick="openEditModal(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">
                                    Edit
                                </button>
                                <?php if ($p['edit_count'] > 0): ?>
                                    <button class="btn btn-secondary btn-sm history-btn" onclick="openHistoryModal(<?= $p['id'] ?>)">History</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td colspan="5" class="text-end fw-bold">Grand Total:</td>
                        <td><strong class="text-success"><?= number_format($grand_total, 2) ?></strong></td>
                        <td></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="7" class="text-muted">No purchases found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Purchase #<span id="editId"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="editBody"></div>
        </div>
    </div>
</div>

<!-- History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="modal-title">Edit History for Purchase #<span id="histId"></span></h5>
                <div>
                    <button type="button" class="btn btn-light btn-sm me-2" onclick="exportToExcel()">
                        <i class="bi bi-file-earmark-excel me-1"></i> Export to Excel
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body" id="histBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
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
    const row = tbody.insertRow();
    row.innerHTML = `
        <td><input type="text" name="item_name[]" class="form-control form-control-sm" required></td>
        <td><input type="text" name="hs_code_item[]" class="form-control form-control-sm"></td>
        <td><input type="number" step="0.001" name="quantity[]" class="form-control form-control-sm qty" required></td>
        <td><input type="number" step="0.01" name="rate[]" class="form-control form-control-sm rate" required></td>
        <td><input type="text" name="unit[]" class="form-control form-control-sm" required></td>
        <td class="total text-end fw-bold">0.00</td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="this.closest('tr').remove(); calcTotal()">Remove</button></td>`;
    row.querySelectorAll('.qty, .rate').forEach(el => el.addEventListener('input', calcTotal));
    calcTotal();
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('#itemRows tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('.qty')?.value || 0);
        const rate = parseFloat(tr.querySelector('.rate')?.value || 0);
        const t = qty * rate;
        tr.querySelector('.total').textContent = t.toFixed(2);
        total += t;
    });
    const discount = parseFloat(document.getElementById('discount')?.value || 0);
    const vat = document.getElementById('vat_option')?.value === '13' ? 13 : 0;
    const taxable = Math.max(total - discount, 0);
    const net = vat === 13 ? taxable * 1.13 : taxable;
    document.getElementById('netTotal').textContent = net.toFixed(2);
}

function openHistoryModal(id) {
    document.getElementById('histId').textContent = id;
    const body = document.getElementById('histBody');
    body.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-info"></div></div>';

    fetch('get_purchase_history.php?purchase_id=' + id)
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
                        <p class="mb-3"><strong>Company:</strong> ${e(h.company_name)} | <strong>Date:</strong> ${e(h.transaction_date)}</p>
                        ${tbl}
                    </div>
                </div>`;
            });
            body.innerHTML = html;
        })
        .catch(err => {
            console.error(err);
            body.innerHTML = '<p class="text-danger text-center">Failed to load history.</p>';
        });

    new bootstrap.Modal('#historyModal').show();
}

function exportToExcel() {
    if (!currentHistoryData || currentHistoryData.length === 0) {
        alert("No history to export!");
        return;
    }

    const wb = XLSX.utils.book_new();
    const ws_data = [["Edit Date", "Editor", "Reason", "Company", "Bill Date", "Item", "Qty", "Rate", "Unit", "Amount"]];
    let grandTotal = 0;

    currentHistoryData.forEach(h => {
        let items = JSON.parse(h.items_json || '[]');
        let billTotal = 0;
        items.forEach((it, i) => {
            const amt = (it.quantity || 0) * (it.rate || 0);
            billTotal += amt;
            if (i === 0) {
                ws_data.push([h.edited_at, h.editor_name || 'Unknown', h.edit_reason, h.company_name, h.transaction_date, it.name, it.quantity, it.rate, it.unit, amt.toFixed(2)]);
            } else {
                ws_data.push(["", "", "", "", "", it.name, it.quantity, it.rate, it.unit, amt.toFixed(2)]);
            }
        });
        grandTotal += billTotal;
        ws_data.push(["", "", "", "", "BILL TOTAL", "", "", "", "", billTotal.toFixed(2)]);
        ws_data.push([]);
    });
    ws_data.push(["", "", "", "", "GRAND TOTAL", "", "", "", "", grandTotal.toFixed(2)]);

    const ws = XLSX.utils.aoa_to_sheet(ws_data);
    XLSX.utils.book_append_sheet(wb, ws, "History");
    XLSX.writeFile(wb, `Purchase_${document.getElementById('histId').textContent}_History.xlsx`);
}
</script>
<script>
function e(t) { 
    const d = document.createElement('div'); 
    d.textContent = t || ''; 
    return d.innerHTML; 
}

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

    document.getElementById('editBody').innerHTML = `
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

    // Re-attach event listeners
    document.querySelectorAll('.qty, .rate, #discount, #vat_option').forEach(el => {
        el.addEventListener('input', calcTotal);
        el.addEventListener('change', calcTotal);
    });
    calcTotal();

    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>
</body>
</html>
