<?php
// SuperAdmin/order_history.php
session_start();
require '../vendor/autoload.php';           // PhpSpreadsheet
require '../Common/connection.php';
require '../Common/nepali_date.php';        // ← Your file (ad_to_bs, etc.)

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../Common/login.php');
    exit;
}
if (empty($_SESSION['current_restaurant_id'])) {
    header('Location: index.php');
    exit;
}

$restaurant_id = $_SESSION['current_restaurant_id'];
$chain_id      = $_SESSION['chain_id'];

// === Restaurant name ===
$stmt = $conn->prepare("SELECT name FROM restaurants WHERE id = ? AND chain_id = ?");
$stmt->bind_param("ii", $restaurant_id, $chain_id);
$stmt->execute();
$restaurant = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$restaurant) {
    unset($_SESSION['current_restaurant_id']);
    header('Location: index.php');
    exit;
}
$restaurant_name = htmlspecialchars($restaurant['name']);

// === CORRECT NEPALI DATE LOGIC (using your functions) ===
$today_ad = date('Y-m-d');                     // e.g. 2025-11-24
$today_bs = ad_to_bs($today_ad);               // ← Correct function from your file

// Default: Last 1 Nepali month
$one_month_ago_ad = date('Y-m-d', strtotime('-1 month'));
$one_month_ago_bs = ad_to_bs($one_month_ago_ad);

$start_date_bs = $_POST['start_date'] ?? $_SESSION['sa_order_start'] ?? $one_month_ago_bs;
$end_date_bs   = $_POST['end_date']   ?? $_SESSION['sa_order_end']   ?? $today_bs;

$_SESSION['sa_order_start'] = $start_date_bs;
$_SESSION['sa_order_end']   = $end_date_bs;

if (isset($_POST['clear_filter'])) {
    unset($_SESSION['sa_order_start'], $_SESSION['sa_order_end']);
    header("Location: order_history.php");
    exit;
}

// === Helper functions (must be BEFORE use) ===
function formatTableDisplay($table_number) {
    if (preg_match('/^\d{7,10}$/', $table_number)) return 'Phone: ' . $table_number;
    if (is_numeric($table_number) && strlen($table_number) <= 3) return 'Table: ' . $table_number;
    return 'Customer: ' . $table_number;
}

function getItemsList($items_json_str, $restaurant_id, $conn) {
    $items = json_decode($items_json_str, true) ?? [];
    if (empty($items)) return '—';

    $item_ids = array_column($items, 'item_id');
    if (empty($item_ids)) return '—';

    $placeholders = str_repeat('?,', count($item_ids) - 1) . '?';
    $stmt = $conn->prepare("SELECT id, item_name FROM menu_items WHERE id IN ($placeholders) AND restaurant_id = ?");
    $stmt->bind_param(str_repeat('i', count($item_ids)) . 'i', ...array_merge($item_ids, [$restaurant_id]));
    $stmt->execute();
    $res = $stmt->get_result();
    $map = [];
    while ($r = $res->fetch_assoc()) $map[$r['id']] = $r['item_name'];
    $stmt->close();

    $names = [];
    foreach ($items as $it) {
        $name = $map[$it['item_id'] ?? 0] ?? 'Unknown';
        if ($it['quantity'] > 1) $name .= " ×{$it['quantity']}";
        if (!empty($it['notes'])) $name .= " ({$it['notes']})";
        $names[] = $name;
    }
    return implode(', ', $names);
}
// ==================== EXCEL EXPORT ====================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $conditions = "bc.restaurant_id = ? AND o.status = 'paid'";
    $params = [$restaurant_id];
    $types = "i";

    if ($start_date_bs && $end_date_bs) {
        $conditions .= " AND DATE(o.created_at) BETWEEN ? AND ?";
        $params[] = $start_date_bs;
        $params[] = $end_date_bs;
        $types .= "ss";
    }

    $stmt = $conn->prepare("
        SELECT 
            o.created_at AS order_date, 
            bc.bill_printed_at AS printed_at, 
            o.table_number, 
            o.items, 
            bc.last_bill_number AS bill_number, 
            bc.net_total, 
            bc.payment_method, 
            u.username AS entered_by
        FROM orders o 
        JOIN bill_counter bc ON o.id = bc.order_id
        LEFT JOIN users u ON o.order_by = u.id
        WHERE $conditions 
        ORDER BY o.created_at DESC
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $grand_total = 0;
    $rows = [];

    while ($order = $result->fetch_assoc()) {
        $grand_total += $order['net_total'];

        $rows[] = [
            substr($order['order_date'], 0, 16),                                      // Order Date (BS)
            $order['printed_at'] ? substr($order['printed_at'], 0, 16) : '—',         // Bill Printed
            formatTableDisplay($order['table_number']),                               // Table/Customer
            strip_tags(getItemsList($order['items'], $restaurant_id, $conn)),         // Items
            $order['bill_number'] ?? '—',                                             // Bill No
            number_format($order['net_total'], 2),                                    // Net Total (Rs)
            ucfirst($order['payment_method'] ?? '—'),                                 // Payment Method
            $order['entered_by'] ?? 'Unknown'                                         // Entered By
        ];
    }
    $stmt->close();

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Title
    $sheet->setCellValue('A1', "Order History - {$restaurant_name}");
    $sheet->mergeCells('A1:H1');
    $sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Period
    $sheet->setCellValue('A2', "Period: {$start_date_bs} to {$end_date_bs} (BS)");
    $sheet->mergeCells('A2:H2');
    $sheet->getStyle('A2')->getFont()->setBold(true);

    // Headers
    $headers = ['Order Date (BS)', 'Bill Printed', 'Table/Customer', 'Items', 'Bill No', 'Net Total (Rs)', 'Payment Method', 'Entered By'];
    $sheet->fromArray($headers, null, 'A4');
    $sheet->getStyle('A4:H4')->getFont()->setBold(true);
    $sheet->getStyle('A4:H4')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('1a73e8');
    $sheet->getStyle('A4:H4')->getFont()->getColor()->setARGB('FFFFFFFF');

    // Data rows
    $sheet->fromArray($rows, null, 'A5');

    // Grand Total Row
    if (!empty($rows)) {
        $last_row = 5 + count($rows);
        $sheet->setCellValue('F' . $last_row, 'GRAND TOTAL');
        $sheet->setCellValue('G' . $last_row, number_format($grand_total, 2));
        $sheet->getStyle('F' . $last_row . ':H' . $last_row)->getFont()->setBold(true);
        $sheet->getStyle('F' . $last_row . ':H' . $last_row)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('fff3cd');
    }

    // Auto-size columns
    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Add borders to the whole data area
    $data_end_row = empty($rows) ? 4 : (5 + count($rows));
    $sheet->getStyle("A4:H{$data_end_row}")->getBorders()->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

    // Filename with proper date range and .xlsx extension
    $safe_restaurant_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $restaurant_name);
    $filename = "Order_History_{$safe_restaurant_name}_{$start_date_bs}_to_{$end_date_bs}.xlsx";

    // Send proper headers for Excel download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    header('Expires: 0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// === MAIN DISPLAY QUERY ===
$conditions = "bc.restaurant_id = ? AND o.status = 'paid'";
$params = [$restaurant_id];
$types = "i";

if ($start_date_bs && $end_date_bs) {
    $conditions .= " AND DATE(o.created_at) BETWEEN ? AND ?";
    $params[] = $start_date_bs;
    $params[] = $end_date_bs;
    $types .= "ss";
}

$stmt = $conn->prepare("
    SELECT o.*, o.created_at as order_date, bc.bill_printed_at as printed_at, 
           bc.net_total, bc.payment_method, bc.last_bill_number as bill_number, 
           o.items, o.table_number
    FROM orders o 
    JOIN bill_counter bc ON o.id = bc.order_id
    WHERE $conditions 
    ORDER BY o.created_at DESC
    LIMIT 500
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$grand_total = 0;
$orders = [];
while ($row = $result->fetch_assoc()) {
    $grand_total += $row['net_total'];
    $orders[] = $row;
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order History - <?= $restaurant_name ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); min-height: 100vh; }
        .items-list { max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .items-list:hover { white-space: normal; overflow: visible; background: #f8f9fa; }
        .total-row { background: #fff3cd !important; font-weight: bold; font-size: 1.2rem; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container py-4">
    <h3 class="text-center text-white mb-4 fw-bold">
        Order History - <?= $restaurant_name ?>
    </h3>

    <div class="text-start mb-3">
        <a href="view_branch.php" class="btn btn-outline-light">Back</a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label text-muted small">Start Date (BS)</label>
                    <input type="text" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date_bs) ?>" placeholder="2082-01-01">
                </div>
                <div class="col-md-4">
                    <label class="form-label text-muted small">End Date (BS)</label>
                    <input type="text" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date_bs) ?>" placeholder="2082-12-30">
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">Filter</button>
                    <button type="submit" name="clear_filter" value="1" class="btn btn-outline-secondary">Clear</button>
                </div>
            </form>

            <div class="mt-3 text-end">
                <a href="order_history.php?export=excel" class="btn btn-success">
                    Export to Excel (.xlsx)
                </a>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle bg-white rounded shadow-sm">
            <thead class="table-dark">
                <tr>
                    <th>Order Date</th>
                    <th>Bill Printed</th>
                    <th>Table/Customer</th>
                    <th>Items</th>
                    <th>Bill No</th>
                    <th>Net Total</th>
                    <th>Payment</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $row): ?>
                <tr>
                    <td><?= substr($row['order_date'], 0, 16) ?></td>
                    <td><?= $row['printed_at'] ? substr($row['printed_at'], 0, 16) : '—' ?></td>
                    <td><?= htmlspecialchars(formatTableDisplay($row['table_number'])) ?></td>
                    <td class="items-list"><?= htmlspecialchars(getItemsList($row['items'], $restaurant_id, $conn)) ?></td>
                    <td><strong><?= htmlspecialchars($row['bill_number'] ?? '—') ?></strong></td>
                    <td><strong><?= number_format($row['net_total'], 2) ?></strong></td>
                    <td><?= ucfirst(htmlspecialchars($row['payment_method'] ?? '—')) ?></td>
                    <td>
                        <a href="bill.php?order_id=<?= $row['id'] ?>&rest_id=<?= $restaurant_id ?>" 
                           target="_blank" class="btn btn-success btn-sm">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (!empty($orders)): ?>
                <tr class="total-row">
                    <td colspan="5" class="text-end pe-4">GRAND TOTAL:</td>
                    <td colspan="3">Rs <?= number_format($grand_total, 2) ?></td>
                </tr>
                <?php else: ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">No paid orders found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../Common/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>