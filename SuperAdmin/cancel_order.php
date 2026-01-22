<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;

if (empty($_SESSION['logged_in']) || empty($_SESSION['current_restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

$restaurant_id = (int)$_SESSION['current_restaurant_id'];
$restaurant_name = $_SESSION['current_restaurant_name'] ?? 'Restaurant';

// === Fetch menu items for name lookup ===
$menu_items = [];
$menu_query = "SELECT id, item_name FROM menu_items WHERE restaurant_id = ? AND status = 'available'";
$menu_stmt = $conn->prepare($menu_query);
if ($menu_stmt) {
    $menu_stmt->bind_param("i", $restaurant_id);
    $menu_stmt->execute();
    $menu_result = $menu_stmt->get_result();
    while ($row = $menu_result->fetch_assoc()) {
        $menu_items[$row['id']] = $row['item_name'];
    }
    $menu_stmt->close();
}

// === Fetch deleted/cancelled orders ===
$query = "SELECT o.change_id, o.order_id, o.changed_by, o.change_time, o.old_data, o.remarks,
                 u.username AS changed_by_name
          FROM old_order o
          LEFT JOIN users u ON o.changed_by = u.id
          WHERE o.restaurant_id = ?
          ORDER BY o.change_time DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();

$deleted_orders = [];
while ($row = $result->fetch_assoc()) {
    $old_data = json_decode($row['old_data'], true);

    // Parse items with actual names
    $items = [];
    if (isset($old_data['items']) && is_array($old_data['items'])) {
        foreach ($old_data['items'] as $item) {
            $item_id = $item['item_id'] ?? 0;
            $item_name = $menu_items[$item_id] ?? "Unknown Item (ID: $item_id)";
            $quantity = $item['quantity'] ?? 0;
            $notes = $item['notes'] ?? '';

            $items[] = [
                'name'     => $item_name,
                'quantity' => $quantity,
                'notes'    => $notes
            ];
        }
    }

    $deleted_orders[] = [
        'change_id'     => $row['change_id'],
        'order_id'      => $row['order_id'],
        'changed_by'    => $row['changed_by_name'] ?? 'Unknown (ID: ' . $row['changed_by'] . ')',
        'change_time'   => $row['change_time'],
        'total_amount'  => $old_data['total_amount'] ?? '0.00',
        'table_number'  => $old_data['table_number'] ?? '-',
        'items'         => $items,
        'remarks'       => $row['remarks'] ?? '-'
    ];
}
$stmt->close();

// === EXCEL EXPORT ===
if (isset($_POST['export_excel'])) {
    ob_end_clean();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Title
    $sheet->setCellValue('A1', 'DELETED / CANCELLED ORDERS REPORT');
    $sheet->mergeCells('A1:H1');
    $sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Restaurant & Date
    $sheet->setCellValue('A2', "Restaurant: {$restaurant_name}");
    $sheet->mergeCells('A2:D2');
    $sheet->getStyle('A2')->getFont()->setBold(true);

    $sheet->setCellValue('E2', 'Generated: ' . date('Y-m-d H:i:s'));
    $sheet->mergeCells('E2:H2');
    $sheet->getStyle('E2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // Headers
    $row = 4;
    $headers = ['#', 'Order ID', 'Deleted/Edited By', 'Date & Time', 'Table', 'Total Amount', 'Items Ordered', 'Remarks'];
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . $row, $h);
        $sheet->getStyle($col . $row)->getFont()->setBold(true);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }
    $row++;

    // Data rows
    $serial = 1;
    foreach ($deleted_orders as $order) {
        $item_list = [];
        foreach ($order['items'] as $it) {
            $text = $it['name'] . " x" . $it['quantity'];
            if (!empty($it['notes'])) {
                $text .= " (Note: " . $it['notes'] . ")";
            }
            $item_list[] = $text;
        }

        $sheet->setCellValue('A' . $row, $serial++);
        $sheet->setCellValue('B' . $row, $order['order_id']);
        $sheet->setCellValue('C' . $row, $order['changed_by']);
        $sheet->setCellValue('D' . $row, $order['change_time']);
        $sheet->setCellValue('E' . $row, $order['table_number']);
        $sheet->setCellValue('F' . $row, 'Rs. ' . number_format($order['total_amount'], 2));
        $sheet->setCellValue('G' . $row, implode("\n", $item_list)); // Line break for readability
        $sheet->setCellValue('H' . $row, $order['remarks']);

        $sheet->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('G' . $row)->getAlignment()->setWrapText(true);
        $row++;
    }

    // Filename
    $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $restaurant_name);
    $filename = "Deleted_Orders_{$safe_name}_" . date('Y-m-d') . ".xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Deleted Orders Report - <?= htmlspecialchars($restaurant_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background:#f8f9fa; }
        .card { box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: none; }
        .table th { background:#e9ecef; }
        .item-badge { font-size: 0.9em; }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-fluid mt-4 mb-5">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2 class="fw-bold text-danger"><i class="bi bi-exclamation-triangle"></i> Deleted / Cancelled Orders</h2>
                    <p class="text-muted mb-0">Full audit trail of edited or deleted orders with item names</p>
                </div>
                <div>
                    <form method="POST" class="d-inline">
                        <button type="submit" name="export_excel" class="btn btn-success">
                            <i class="bi bi-file-earmark-excel"></i> Export to Excel
                        </button>
                    </form>
                    <a href="view_branch.php" class="btn btn-secondary ms-2">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($deleted_orders)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-check-circle fs-1 mb-3 text-success"></i>
                    <h5>No deleted or cancelled orders found</h5>
                    <p>All orders are intact and properly recorded.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Order ID</th>
                                <th>Deleted/Edited By</th>
                                <th>Date & Time</th>
                                <th>Table</th>
                                <th>Total Amount</th>
                                <th>Items Ordered</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deleted_orders as $index => $order): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><strong>#<?= $order['order_id'] ?></strong></td>
                                    <td><?= htmlspecialchars($order['changed_by']) ?></td>
                                    <td><?= date('d M Y, h:i A', strtotime($order['change_time'])) ?></td>
                                    <td><?= htmlspecialchars($order['table_number']) ?></td>
                                    <td class="text-end fw-bold text-danger">Rs. <?= number_format($order['total_amount'], 2) ?></td>
                                    <td>
                                        <?php if (empty($order['items'])): ?>
                                            <em class="text-muted">No items</em>
                                        <?php else: ?>
                                            <?php foreach ($order['items'] as $it): ?>
                                                <div class="mb-1">
                                                    <span class="badge bg-primary item-badge">
                                                        <?= htmlspecialchars($it['name']) ?> × <?= $it['quantity'] ?>
                                                    </span>
                                                    <?php if (!empty($it['notes'])): ?>
                                                        <br><small class="text-muted ms-2">Note: <?= htmlspecialchars($it['notes']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($order['remarks']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php include '../Common/footer.php'; ?>
</body>
</html>