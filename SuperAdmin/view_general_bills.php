<?php
session_start();
require '../vendor/autoload.php';
require '../Common/connection.php';
require '../Common/nepali_date.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['current_restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

$restaurant_id   = (int)$_SESSION['current_restaurant_id'];
$user_id         = (int)$_SESSION['user_id'];
$message         = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// ==================== SAVE EDIT (WORKING) ====================
if ($_POST['action'] ?? '' === 'save_edit') {
    $bill_id           = (int)$_POST['bill_id'];
    $purchased_from    = trim($_POST['purchased_from']);
    $purchase_date_bs  = trim($_POST['purchase_date_bs']);
    $edit_reason       = trim($_POST['edit_reason'] ?? '');
    $items_json        = $_POST['items_json'] ?? '[]';

    if (!$purchased_from || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchase_date_bs) || $edit_reason === '') {
        $_SESSION['message'] = "Please fill all fields and Edit Reason.";
        header("Location: view_general_bills.php");
        exit;
    }

    $fiscal_year = get_fiscal_year($purchase_date_bs);
    $now_bs = nepali_date_time();

    $conn->autocommit(false);
    try {
        // 1. Backup to history
        $stmt = $conn->prepare("INSERT INTO general_bill_history 
            (general_bill_id, restaurant_id, purchased_from, purchase_date, fiscal_year, items_json, 
             inserted_by, inserted_at, edited_by, edited_at, edit_reason)
            SELECT id, restaurant_id, purchased_from, purchase_date, fiscal_year, items_json,
                   inserted_by, inserted_at, ?, ?, ?
            FROM general_bill WHERE id = ? AND restaurant_id = ?");
        $stmt->bind_param("issii", $user_id, $now_bs, $edit_reason, $bill_id, $restaurant_id);
        $stmt->execute();
        $stmt->close();

        // 2. Update main bill
        $stmt = $conn->prepare("UPDATE general_bill SET 
            purchased_from = ?, purchase_date = ?, fiscal_year = ?, items_json = ?,
            inserted_by = ?, inserted_at = ?
            WHERE id = ? AND restaurant_id = ?");
        $stmt->bind_param("ssssisii", $purchased_from, $purchase_date_bs, $fiscal_year, $items_json, $user_id, $now_bs, $bill_id, $restaurant_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $_SESSION['message'] = "Bill updated successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "Error: " . $e->getMessage();
    }
    header("Location: view_general_bills.php");
    exit;
}

// === FILTER ===
$today_ad = date('Y-m-d');
$today_bs = ad_to_bs($today_ad);
$one_month_ago_ad = date('Y-m-d', strtotime('-1 month'));
$one_month_ago_bs = ad_to_bs($one_month_ago_ad);

$start_date = $_POST['start_date'] ?? $_SESSION['gb_start'] ?? $one_month_ago_bs;
$end_date   = $_POST['end_date']   ?? $_SESSION['gb_end']   ?? $today_bs;

$_SESSION['gb_start'] = $start_date;
$_SESSION['gb_end']   = $end_date;

if (isset($_POST['clear_filter'])) {
    unset($_SESSION['gb_start'], $_SESSION['gb_end']);
    header("Location: view_general_bills.php");
    exit;
}

// === FETCH BILLS ===
$conditions = "restaurant_id = ?";
$params = [$restaurant_id];
$types = "i";

if ($start_date && $end_date) {
    $conditions .= " AND purchase_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

$stmt = $conn->prepare("SELECT id, purchased_from, purchase_date, fiscal_year, items_json 
                        FROM general_bill WHERE $conditions 
                        ORDER BY purchase_date DESC, id DESC");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$grand_total = 0;
$bills = [];
while ($b = $result->fetch_assoc()) {
    $items = json_decode($b['items_json'] ?? '[]', true) ?? [];
    $total = 0;
    foreach ($items as $it) {
        $total += ($it['quantity'] ?? 0) * ($it['rate'] ?? 0);
    }
    $b['total_amount'] = $total;
    $grand_total += $total;
    $bills[] = $b;
}

// ==================== PROFESSIONAL EXCEL EXPORT (ITEM-WISE + ENTERED BY) ====================
if (isset($_POST['export_excel'])) {
    $restaurant_name = $_SESSION['current_restaurant_name'] ?? 'Restaurant';
    $restaurant_name = trim(preg_replace('/[^a-zA-Z0-9\s\-]/', '', $restaurant_name)) ?: 'Restaurant';

    $clean_start = str_replace('-', '', $start_date);
    $clean_end   = str_replace('-', '', $end_date);
    $filename = "General_Bills_{$restaurant_name}_{$clean_start}_to_{$clean_end}.xlsx";

    // Re-fetch bills with username (inserted_by → users)
    $conditions = "gb.restaurant_id = ?";
    $params = [$restaurant_id];
    $types = "i";

    if ($start_date && $end_date) {
        $conditions .= " AND gb.purchase_date BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    }

    $stmt = $conn->prepare("
        SELECT gb.id, gb.purchased_from, gb.purchase_date, gb.fiscal_year, gb.items_json, 
               u.username AS entered_by
        FROM general_bill gb
        LEFT JOIN users u ON gb.inserted_by = u.id
        WHERE $conditions
        ORDER BY gb.purchase_date DESC, gb.id DESC
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Title
    $sheet->setCellValue('A1', "GENERAL BILLS REGISTER");
    $sheet->mergeCells('A1:J1');
    $sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Restaurant & Period
    $sheet->setCellValue('A2', "Restaurant: {$restaurant_name}");
    $sheet->mergeCells('A2:E2');
    $sheet->getStyle('A2')->getFont()->setBold(true);

    $sheet->setCellValue('F2', "Period: {$start_date} to {$end_date} (BS)");
    $sheet->mergeCells('F2:J2');
    $sheet->getStyle('F2')->getFont()->setBold(true);
    $sheet->getStyle('F2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

    // Headers
    $headers = ['Bill ID', 'Vendor', 'Purchase Date', 'Fiscal Year', 'Item Name', 'Quantity', 'Rate', 'Unit', 'Amount (Rs)', 'Entered By'];
    $sheet->fromArray($headers, null, 'A4');
    $sheet->getStyle('A4:J4')->getFont()->setBold(true);
    $sheet->getStyle('A4:J4')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('1a73e8');
    $sheet->getStyle('A4:J4')->getFont()->getColor()->setARGB('FFFFFFFF');

    // Data
    $row = 5;
    $grand_total = 0;

    while ($bill = $result->fetch_assoc()) {
        $items = json_decode($bill['items_json'] ?? '[]', true) ?? [];
        $bill_total = 0;
        $first_item = true;

        if (empty($items)) {
            // Empty bill handling
            $sheet->setCellValue('A' . $row, $bill['id']);
            $sheet->setCellValue('B' . $row, $bill['purchased_from']);
            $sheet->setCellValue('C' . $row, $bill['purchase_date']);
            $sheet->setCellValue('D' . $row, $bill['fiscal_year']);
            $sheet->setCellValue('E' . $row, '(No items)');
            $sheet->setCellValue('J' . $row, $bill['entered_by'] ?? 'Unknown');
            $row++;
            $row++; // empty line
            continue;
        }

        foreach ($items as $item) {
            $qty = (float)($item['quantity'] ?? 0);
            $rate = (float)($item['rate'] ?? 0);
            $amt = $qty * $rate;
            $bill_total += $amt;

            $sheet->setCellValue('A' . $row, $first_item ? $bill['id'] : '');
            $sheet->setCellValue('B' . $row, $first_item ? $bill['purchased_from'] : '');
            $sheet->setCellValue('C' . $row, $first_item ? $bill['purchase_date'] : '');
            $sheet->setCellValue('D' . $row, $first_item ? $bill['fiscal_year'] : '');
            $sheet->setCellValue('E' . $row, $item['item_name'] ?? 'Unknown');
            $sheet->setCellValue('F' . $row, number_format($qty, 3));
            $sheet->setCellValue('G' . $row, number_format($rate, 2));
            $sheet->setCellValue('H' . $row, $item['unit'] ?? '');
            $sheet->setCellValue('I' . $row, number_format($amt, 2)); // Correct individual amount
            $sheet->setCellValue('J' . $row, $first_item ? ($bill['entered_by'] ?? 'Unknown') : '');

            // Right align numbers
            $sheet->getStyle('F' . $row . ':I' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

            $first_item = false;
            $row++;
        }

        // Bill Subtotal
        $sheet->setCellValue('H' . $row, 'Bill Total');
        $sheet->setCellValue('I' . $row, number_format($bill_total, 2));
        $sheet->getStyle('H' . $row . ':I' . $row)->getFont()->setBold(true);
        $sheet->getStyle('I' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $row++;

        // Separator
        $row++;
        $grand_total += $bill_total;
    }

    // Grand Total
    $sheet->setCellValue('H' . $row, 'GRAND TOTAL');
    $sheet->setCellValue('I' . $row, number_format($grand_total, 2));
    $sheet->getStyle('H' . $row . ':I' . $row)
        ->getFont()->setBold(true)->setSize(12)
        ->getColor()->setARGB('FF0000');
    $sheet->getStyle('I' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

    // Auto-size & borders
    foreach (range('A', 'J') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    $last_row = $row;
    $sheet->getStyle("A4:J{$last_row}")->getBorders()->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

    // Download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    header('Expires: 0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>General Bills</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); min-height: 100vh; }
        .card { box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .total-row { background: #fff3cd !important; font-weight: bold; font-size: 1.2rem; }
        .text-end { text-align: right !important; }
        .btn { z-index: 1; position: relative; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h3 class="text-center text-dark fw-bold mb-4">General Bills</h3>

    <?php if ($message): ?>
        <div class="alert <?= strpos($message,'success')!==false ? 'alert-success' : 'alert-danger' ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter + Export -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="POST" class="row g-3 align-items-end mb-3">
                <div class="col-md-4">
                    <label class="form-label">Start Date (BS)</label>
                    <input type="text" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" placeholder="2082-01-01">
                </div>
                <div class="col-md-4">
                    <label class="form-label">End Date (BS)</label>
                    <input type="text" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" placeholder="2082-12-30">
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">Filter</button>
                    <button type="submit" name="clear_filter" value="1" class="btn btn-outline-secondary">Clear</button>
                </div>
            </form>

            <?php if (!empty($bills)): ?>
            <div class="text-end">
                <form method="POST" class="d-inline">
                    <input type="hidden" name="export_excel" value="1">
                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                    <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-file-earmark-excel"></i> Export to Excel
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bills Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-primary">
                        <tr>
                            <th>#</th>
                            <th>Purchased From</th>
                            <th>Date (BS)</th>
                            <th>Fiscal Year</th>
                            <th class="text-end">Total Amount (Rs)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bills)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">No bills found</td></tr>
                        <?php else: $i=1; foreach($bills as $b): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($b['purchased_from']) ?></td>
                            <td><?= $b['purchase_date'] ?></td>
                            <td><?= $b['fiscal_year'] ?></td>
                            <td class="text-end fw-bold text-success">Rs <?= number_format($b['total_amount'], 2) ?></td>
                            <td>
                                <button type="button" class="btn btn-warning btn-sm me-1"
                                        onclick='openEditModal(<?= $b["id"] ?>, <?= json_encode($b, JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                                    Edit
                                </button>
                                <button type="button" class="btn btn-info btn-sm text-white"
                                        onclick="openHistoryModal(<?= $b['id'] ?>)">
                                    History
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="4" class="text-end pe-4">GRAND TOTAL:</td>
                            <td class="text-end text-success">Rs <?= number_format($grand_total, 2) ?></td>
                            <td></td>
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
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">Edit Bill #<span id="editId"></span></h5>
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
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Edit History #<span id="histId"></span></h5>
                <div>
                    <button type="button" class="btn btn-light btn-sm me-2" onclick="exportHistoryToExcel()">Export History</button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body" id="histBody">
                <div class="text-center p-4"><div class="spinner-border text-info"></div></div>
            </div>
        </div>
    </div>
</div>

<script>
function e(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

let currentHistoryData = [];

function openEditModal(id, bill) {
    document.getElementById('editId').textContent = id;
    let items = [];
    try { items = JSON.parse(bill.items_json || '[]'); } catch(e) { items = []; }

    let rows = '';
    items.forEach((item, i) => {
        rows += `<tr>
            <td><input type="text" name="item[${i}][name]" class="form-control form-control-sm" value="${e(item.item_name ?? '')}" required></td>
            <td><input type="number" step="0.001" name="item[${i}][qty]" class="form-control form-control-sm qty" value="${item.quantity ?? 0}" required></td>
            <td><input type="number" step="0.01" name="item[${i}][rate]" class="form-control form-control-sm rate" value="${item.rate ?? 0}" required></td>
            <td><input type="text" name="item[${i}][unit]" class="form-control form-control-sm" value="${e(item.unit ?? '')}" required></td>
            <td class="total text-end fw-bold">0.00</td>
            <td><button type="button" class="btn btn-danger btn-sm" onclick="this.closest('tr').remove(); calcTotal()">Remove</button></td>
        </tr>`;
    });

    document.getElementById('editForm').innerHTML = `
        <form method="post" onsubmit="return prepareJson()">
            <input type="hidden" name="action" value="save_edit">
            <input type="hidden" name="bill_id" value="${id}">
            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label>Purchased From</label>
                    <input type="text" name="purchased_from" class="form-control" value="${e(bill.purchased_from)}" required>
                </div>
                <div class="col-md-4">
                    <label>Date (BS)</label>
                    <input type="text" name="purchase_date_bs" class="form-control" value="${bill.purchase_date}" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="text-danger fw-bold">Edit Reason (Required)</label>
                <textarea name="edit_reason" class="form-control" rows="3" required placeholder="Why are you editing this bill?"></textarea>
            </div>
            <hr>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light"><tr><th>Item</th><th>Qty</th><th>Rate</th><th>Unit</th><th>Total</th><th></th></tr></thead>
                    <tbody id="itemRows">${rows || '<tr><td colspan="6" class="text-center text-muted">No items</td></tr>'}</tbody>
                </table>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm mb-3" onclick="addRow()">+ Add Item</button>
            <div class="text-end mb-3"><h5>Grand Total: <span id="grandTotal">0.00</span> NPR</h5></div>
            <input type="hidden" name="items_json" id="itemsJson">
            <div class="text-center">
                <button type="submit" class="btn btn-success btn-lg px-5">Save Changes</button>
            </div>
        </form>`;

    document.querySelectorAll('.qty, .rate').forEach(el => el.addEventListener('input', calcTotal));
    calcTotal();
    new bootstrap.Modal('#editModal').show();
}

function addRow() {
    const tbody = document.getElementById('itemRows');
    const idx = tbody.rows.length;
    const row = tbody.insertRow();
    row.innerHTML = `
        <td><input type="text" name="item[${idx}][name]" class="form-control form-control-sm" required></td>
        <td><input type="number" step="0.001" name="item[${idx}][qty]" class="form-control form-control-sm qty" required></td>
        <td><input type="number" step="0.01" name="item[${idx}][rate]" class="form-control form-control-sm rate" required></td>
        <td><input type="text" name="item[${idx}][unit]" class="form-control form-control-sm" required></td>
        <td class="total text-end fw-bold">0.00</td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="this.closest('tr').remove(); calcTotal()">Remove</button></td>`;
    row.querySelectorAll('.qty, .rate').forEach(el => el.addEventListener('input', calcTotal));
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('#itemRows tr').forEach(row => {
        const qty = parseFloat(row.querySelector('.qty')?.value) || 0;
        const rate = parseFloat(row.querySelector('.rate')?.value) || 0;
        const amt = qty * rate;
        row.querySelector('.total').textContent = amt.toFixed(2);
        total += amt;
    });
    document.getElementById('grandTotal').textContent = total.toFixed(2);
}

function prepareJson() {
    const items = [];
    document.querySelectorAll('#itemRows tr').forEach(row => {
        const name = row.querySelector('input[name*="name"]')?.value.trim();
        const qty = parseFloat(row.querySelector('.qty')?.value) || 0;
        const rate = parseFloat(row.querySelector('.rate')?.value) || 0;
        const unit = row.querySelector('input[name*="unit"]')?.value.trim();
        if (name && qty > 0 && rate > 0 && unit) {
            items.push({item_name: name, quantity: qty, rate: rate, unit: unit});
        }
    });
    if (items.length === 0) {
        alert("Please add at least one item!");
        return false;
    }
    document.getElementById('itemsJson').value = JSON.stringify(items);
    return true;
}

function openHistoryModal(id) {
    document.getElementById('histId').textContent = id;
    const body = document.getElementById('histBody');
    body.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-info"></div><p>Loading history...</p></div>';

    fetch(`get_bill_history.php?bill_id=${id}&restaurant_id=<?= $restaurant_id ?>`)
        .then(r => r.json())
        .then(data => {
            currentHistoryData = data || [];
            if (!data || data.length === 0) {
                body.innerHTML = '<p class="text-center text-muted fs-5">No edit history available yet.</p>';
                return;
            }

            let html = '';
            data.forEach(h => {
                let items = [];
                try { items = JSON.parse(h.items_json || '[]'); } catch(e) { items = []; }

                let total = 0;
                let tbl = '<table class="table table-sm table-bordered mb-3"><thead class="table-light"><tr><th>Item</th><th>Qty</th><th>Rate</th><th>Unit</th><th>Total</th></tr></thead><tbody>';
                items.forEach(i => {
                    const qty = parseFloat(i.quantity) || 0;
                    const rate = parseFloat(i.rate) || 0;
                    const amt = qty * rate;
                    total += amt;
                    tbl += `<tr>
                        <td>${e(i.item_name || 'Unknown')}</td>
                        <td class="text-end">${qty.toFixed(3)}</td>
                        <td class="text-end">${rate.toFixed(2)}</td>
                        <td>${e(i.unit || '')}</td>
                        <td class="text-end fw-bold">${amt.toFixed(2)}</td>
                    </tr>`;
                });
                tbl += '</tbody></table>';
                tbl += `<div class="text-end mb-3"><h5>Grand Total: <strong class="text-success">${total.toFixed(2)} NPR</strong></h5></div>`;

                html += `
                <div class="card mb-4 shadow-sm border-primary">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between">
                            <div><strong>Edited by:</strong> ${e(h.editor_name || 'Unknown')}</div>
                            <div><strong>On:</strong> ${e(h.edited_at)}</div>
                        </div>
                        <div class="mt-2"><em>Reason: ${e(h.edit_reason)}</em></div>
                    </div>
                    <div class="card-body">
                        <p class="mb-3"><strong>Vendor:</strong> ${e(h.purchased_from)} | <strong>Date:</strong> ${h.purchase_date}</p>
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

function exportHistoryToExcel() {
    if (!currentHistoryData || currentHistoryData.length === 0) {
        alert("No history to export!");
        return;
    }

    const wb = XLSX.utils.book_new();
    const ws_data = [
        ["Edit Date", "Editor", "Reason", "Vendor", "Bill Date (BS)", "Item", "Qty", "Rate", "Unit", "Amount"]
    ];

    let grandTotalAllVersions = 0;

    currentHistoryData.forEach(h => {
        let items = [];
        try { items = JSON.parse(h.items_json || '[]'); } catch(e) { items = []; }

        let billTotal = 0;

        if (items.length === 0) {
            ws_data.push([h.edited_at, h.editor_name || 'Unknown', h.edit_reason, h.purchased_from, h.purchase_date, "No items", "", "", "", "0.00"]);
        } else {
            items.forEach((it, idx) => {
                const qty = parseFloat(it.quantity) || 0;
                const rate = parseFloat(it.rate) || 0;
                const amt = qty * rate;
                billTotal += amt;

                if (idx === 0) {
                    ws_data.push([
                        h.edited_at,
                        h.editor_name || 'Unknown',
                        h.edit_reason,
                        h.purchased_from,
                        h.purchase_date,
                        it.item_name || 'Item',
                        qty,
                        rate,
                        it.unit || '',
                        amt.toFixed(2)
                    ]);
                } else {
                    ws_data.push(["", "", "", "", "", it.item_name || 'Item', qty, rate, it.unit || '', amt.toFixed(2)]);
                }
            });
        }

        grandTotalAllVersions += billTotal;

        // Add Bill Total row
        ws_data.push(["", "", "", "", "", "BILL TOTAL", "", "", "", billTotal.toFixed(2)]);

        // Empty row for separation
        ws_data.push([]);
    });

    // Final Grand Total
    ws_data.push(["", "", "", "", "", "GRAND TOTAL (All Versions)", "", "", "", grandTotalAllVersions.toFixed(2)]);

    const ws = XLSX.utils.aoa_to_sheet(ws_data);
    XLSX.utils.book_append_sheet(wb, ws, "History");

    const billId = document.getElementById('histId').textContent;
    XLSX.writeFile(wb, `General_Bill_${billId}_History.xlsx`);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<?php include '../Common/footer.php'; ?>
</body>
</html>