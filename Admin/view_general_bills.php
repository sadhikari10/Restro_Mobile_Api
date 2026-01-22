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
$user_id       = (int)$_SESSION['user_id'];
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

// ==================== SAVE EDIT ====================
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
    SELECT 
        gb.id, gb.purchased_from, gb.purchase_date, gb.fiscal_year, gb.items_json,
        gb.inserted_at,
        COALESCE(u.username, 'Unknown User') AS inserted_by_name
    FROM general_bill gb
    LEFT JOIN users u ON gb.inserted_by = u.id
    WHERE $conditions
    ORDER BY gb.purchase_date DESC, gb.id DESC
");
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
$stmt->close();

// ==================== DETAILED GROUPED EXCEL EXPORT WITH ORIGINAL CREATOR ====================
if (isset($_POST['export_excel'])) {
    $restaurant_name_clean = trim(preg_replace('/[^a-zA-Z0-9\s\-]/', '', $restaurant_name)) ?: 'Restaurant';
    $clean_start = str_replace('-', '', $start_date);
    $clean_end   = str_replace('-', '', $end_date);
    $filename = "General_Bills_Detailed_{$restaurant_name_clean}_{$clean_start}_to_{$clean_end}.xlsx";

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Title & Info
    $sheet->setCellValue('A1', "GENERAL BILLS DETAILED REGISTER");
    $sheet->mergeCells('A1:L1');
    $sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $sheet->setCellValue('A2', "Restaurant: {$restaurant_name}");
    $sheet->mergeCells('A2:F2');
    $sheet->getStyle('A2')->getFont()->setBold(true);

    $sheet->setCellValue('G2', "Period: {$start_date} to {$end_date} (BS)");
    $sheet->mergeCells('G2:L2');
    $sheet->getStyle('G2')->getFont()->setBold(true);

    // Headers (Row 4)
    $headers = [
        'Bill ID', 'Purchase Date (BS)', 'Vendor', 'Fiscal Year', 
        'Added By', 'Added On', 'Item Name', 'HS Code', 'Quantity', 
        'Rate', 'Unit', 'Line Total'
    ];
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . '4', $h);
        $sheet->getStyle($col . '4')->getFont()->setBold(true);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }
    $sheet->getStyle('A4:L4')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFD9EAD3');

    $rowNum = 5;
    $overallGrandTotal = 0;

    foreach ($bills as $bill) {
        $items = json_decode($bill['items_json'] ?? '[]', true) ?? [];
        if (empty($items)) continue; // Skip empty bills

        $billTotal = 0;

        // === Header Row (only once per bill) ===
        $sheet->setCellValue('A' . $rowNum, $bill['id']);
        $sheet->setCellValue('B' . $rowNum, $bill['purchase_date']);
        $sheet->setCellValue('C' . $rowNum, $bill['purchased_from']);
        $sheet->setCellValue('D' . $rowNum, $bill['fiscal_year']);
        $sheet->setCellValue('E' . $rowNum, $bill['inserted_by_name']);
        $sheet->setCellValue('F' . $rowNum, $bill['inserted_at']);

        $sheet->getStyle("A{$rowNum}:F{$rowNum}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowNum}:L{$rowNum}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF0F0F0');

        $rowNum++;

        // === Item Rows ===
        foreach ($items as $it) {
            $qty = (float)($it['quantity'] ?? 0);
            $rate = (float)($it['rate'] ?? 0);
            $lineTotal = $qty * $rate;
            $billTotal += $lineTotal;

            $sheet->setCellValue('G' . $rowNum, $it['item_name'] ?? 'Item');
            $sheet->setCellValue('H' . $rowNum, $it['hs_code'] ?? ''); // Assuming HS Code might exist; add if needed
            $sheet->setCellValue('I' . $rowNum, $qty);
            $sheet->setCellValue('J' . $rowNum, $rate);
            $sheet->setCellValue('K' . $rowNum, $it['unit'] ?? '');
            $sheet->setCellValue('L' . $rowNum, $lineTotal);

            $rowNum++;
        }

        // === Bill Total Row ===
        $sheet->setCellValue('K' . $rowNum, 'Bill Total:');
        $sheet->setCellValue('L' . $rowNum, $billTotal);

        $sheet->getStyle("K{$rowNum}:L{$rowNum}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowNum}:L{$rowNum}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFE599');

        $overallGrandTotal += $billTotal;

        $rowNum += 2; // Spacing after total
    }

    // === Grand Total ===
    $sheet->setCellValue('K' . $rowNum, 'GRAND TOTAL:');
    $sheet->setCellValue('L' . $rowNum, $overallGrandTotal);
    $sheet->getStyle("K{$rowNum}:L{$rowNum}")->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle("A{$rowNum}:L{$rowNum}")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFD9EAD3');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . urlencode($filename) . '"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>View General Bills - <?= htmlspecialchars($restaurant_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../Common/admin.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .table { background: white; }
        .alert { transition: opacity 1s; }
        #msgBox { max-width: 600px; margin: 0 auto; }
        .back-btn { margin: 1rem 0; }
    </style>
</head>
<body>

<!-- Back to Dashboard Button -->
<div class="container mt-4">
    <div class="back-btn">
        <a href="dashboard.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
        </a>
    </div>
</div>

<div class="container py-4">
    <?php if ($message): ?>
        <div id="msgBox" class="alert alert-info text-center"><?= $message ?></div>
    <?php endif; ?>

    <div class="card shadow-lg">
        <div class="card-header bg-primary text-white text-center">
            <h4 class="mb-0">General Bills - <?= htmlspecialchars($restaurant_name) ?></h4>
        </div>
        <div class="card-body">
            <form method="POST" class="mb-4">
                <div class="row g-3 justify-content-center">
                    <div class="col-md-4">
                        <label class="form-label">Start Date (BS)</label>
                        <input type="text" name="start_date" class="form-control" placeholder="2081-01-01" value="<?= htmlspecialchars($start_date) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">End Date (BS)</label>
                        <input type="text" name="end_date" class="form-control" placeholder="2081-12-32" value="<?= htmlspecialchars($end_date) ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">Filter</button>
                        <button type="submit" name="clear_filter" class="btn btn-secondary me-2">Clear</button>
                        <button type="submit" name="export_excel" class="btn btn-success">Export Excel</button>
                    </div>
                </div>
            </form>

            <?php if (empty($bills)): ?>
                <div class="alert alert-info text-center">No bills found for the selected period.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Bill ID</th>
                                <th>Purchase Date</th>
                                <th>Vendor</th>
                                <th>Items</th>
                                <th>Total (NPR)</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bills as $b): ?>
                                <tr>
                                    <td><?= $b['id'] ?></td>
                                    <td><?= htmlspecialchars($b['purchase_date']) ?></td>
                                    <td><?= htmlspecialchars($b['purchased_from']) ?></td>
                                    <td>
                                        <?php
                                        $items = json_decode($b['items_json'] ?? '[]', true) ?? [];
                                        if (empty($items)) {
                                            echo '<em>No items</em>';
                                        } else {
                                            echo '<ul class="list-unstyled mb-0">';
                                            foreach ($items as $it) {
                                                $qty = (float)($it['quantity'] ?? 0);
                                                $rate = (float)($it['rate'] ?? 0);
                                                echo '<li>' . htmlspecialchars($it['item_name'] ?? 'Item') . 
                                                     ' × ' . $qty . ' ' . htmlspecialchars($it['unit'] ?? '') . 
                                                     ' @ ' . $rate . '</li>';
                                            }
                                            echo '</ul>';
                                        }
                                        ?>
                                    </td>
                                    <td class="fw-bold text-success text-end"><?= number_format($b['total_amount'], 2) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary edit-btn" data-id="<?= $b['id'] ?>">Edit</button>
                                        <button class="btn btn-sm btn-info history-btn" data-id="<?= $b['id'] ?>">History</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-end fw-bold">Grand Total:</td>
                                <td class="text-end fw-bold text-success"><?= number_format($grand_total, 2) ?> NPR</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Edit Bill #<span id="editId"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editForm" method="POST">
                    <input type="hidden" name="action" value="save_edit">
                    <input type="hidden" name="bill_id" id="editBillId">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Purchased From</label>
                            <input type="text" name="purchased_from" id="editVendor" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Purchase Date (BS)</label>
                            <input type="text" name="purchase_date_bs" id="editDate" class="form-control" required>
                        </div>
                    </div>
                    <hr>
                    <h6 class="mb-3">Items</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered" id="editItemsTable">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Quantity</th>
                                    <th>Rate</th>
                                    <th>Unit</th>
                                    <th>Total</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="text-center mb-3">
                        <button type="button" id="addEditItem" class="btn btn-outline-primary">Add Item</button>
                    </div>
                    <div class="text-end mb-3">
                        <h5>Grand Total: <span id="editGrandTotal" class="text-success fw-bold">0.00</span> NPR</h5>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Edit Reason <span class="text-danger">*</span></label>
                        <textarea name="edit_reason" class="form-control" rows="2" required></textarea>
                    </div>
                    <div class="text-end">
                        <button type="submit" class="btn btn-success">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="modal-title" id="historyModalLabel">
                    Edit History for Bill #<span id="histId"></span>
                </h5>
                <div>
                    <button type="button" class="btn btn-light btn-sm me-2" onclick="exportHistoryToExcel()">
                        <i class="bi bi-file-earmark-excel me-1"></i> Export to Excel
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body" id="histBody">
                <!-- History content will be loaded here via JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
let currentHistoryData = null;

function e(str) {
    return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Edit Button
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        fetch(`get_bill.php?bill_id=${id}`)
            .then(r => r.json())
            .then(data => {
                if (!data) {
                    alert('Bill not found');
                    return;
                }

                document.getElementById('editBillId').value = id;
                document.getElementById('editId').textContent = id;
                document.getElementById('editVendor').value = data.purchased_from || '';
                document.getElementById('editDate').value = data.purchase_date || '';

                const tbody = document.getElementById('editItemsTable').querySelector('tbody');
                tbody.innerHTML = '';
                let total = 0;

                const items = JSON.parse(data.items_json || '[]');
                if (items.length === 0) {
                    addEditRow();
                } else {
                    items.forEach(it => {
                        addEditRow(it.item_name, it.quantity, it.rate, it.unit);
                        total += (parseFloat(it.quantity) || 0) * (parseFloat(it.rate) || 0);
                    });
                }

                document.getElementById('editGrandTotal').textContent = total.toFixed(2);
                new bootstrap.Modal('#editModal').show();
            })
            .catch(err => {
                console.error(err);
                alert('Failed to load bill');
            });
    });
});

// Add Edit Item Row
function addEditRow(name = '', qty = 1, rate = 0, unit = 'kg') {
    const tbody = document.getElementById('editItemsTable').querySelector('tbody');
    const row = tbody.insertRow();
    row.innerHTML = `
        <td><input type="text" name="item_name[]" class="form-control" value="${e(name)}" required></td>
        <td><input type="number" name="quantity[]" class="form-control qty" step="0.001" min="0.001" value="${qty}" required></td>
        <td><input type="number" name="rate[]" class="form-control rate" step="0.01" min="0.01" value="${rate}" required></td>
        <td><input type="text" name="unit[]" class="form-control" value="${e(unit)}" required></td>
        <td><input type="number" class="form-control total" value="${(qty * rate).toFixed(2)}" readonly></td>
        <td class="text-center"><button type="button" class="btn btn-danger btn-sm remove_row">Remove</button></td>
    `;
}

document.getElementById('addEditItem').addEventListener('click', () => addEditRow());

document.getElementById('editItemsTable').addEventListener('click', e => {
    if (e.target.classList.contains('remove_row') && e.target.closest('tbody').rows.length > 1) {
        e.target.closest('tr').remove();
        calcEditTotal();
    }
});

document.getElementById('editItemsTable').addEventListener('input', e => {
    if (e.target.matches('.qty, .rate')) {
        const row = e.target.closest('tr');
        const qty = parseFloat(row.querySelector('.qty').value) || 0;
        const rate = parseFloat(row.querySelector('.rate').value) || 0;
        row.querySelector('.total').value = (qty * rate).toFixed(2);
        calcEditTotal();
    }
});

function calcEditTotal() {
    let sum = 0;
    document.querySelectorAll('#editItemsTable .total').forEach(t => sum += parseFloat(t.value) || 0);
    document.getElementById('editGrandTotal').textContent = sum.toFixed(2);
}

// Serialize items before submit
document.getElementById('editForm').addEventListener('submit', e => {
    const rows = document.querySelectorAll('#editItemsTable tbody tr');
    const items = [];
    rows.forEach(r => {
        items.push({
            item_name: r.querySelector('[name="item_name[]"]').value.trim(),
            quantity: parseFloat(r.querySelector('[name="quantity[]"]').value) || 0,
            rate: parseFloat(r.querySelector('[name="rate[]"]').value) || 0,
            unit: r.querySelector('[name="unit[]"]').value.trim()
        });
    });
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'items_json';
    input.value = JSON.stringify(items);
    e.target.appendChild(input);
});

// History Button
document.querySelectorAll('.history-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        document.getElementById('histId').textContent = id;
        const body = document.getElementById('histBody');
        body.innerHTML = '<div class="text-center my-4"><i class="bi bi-hourglass-split fs-1 text-info"></i><p>Loading history...</p></div>';

        fetch(`get_bill_history.php?bill_id=${id}`)
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
    });
});

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

        ws_data.push(["", "", "", "", "", "BILL TOTAL", "", "", "", billTotal.toFixed(2)]);
        ws_data.push([]);
        grandTotalAllVersions += billTotal;
    });

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