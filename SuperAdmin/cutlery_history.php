<?php
// SuperAdmin/cutlery_history.php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin' || empty($_SESSION['current_restaurant_id'])) {
    header('Location: ../login.php');
    exit;
}

$restaurant_id = $_SESSION['current_restaurant_id'];
$cutlery_id = isset($_GET['cutlery_id']) ? (int)$_GET['cutlery_id'] : 0;
$item_name  = isset($_GET['item']) ? htmlspecialchars($_GET['item']) : 'Item';

// Fetch item details
$stmt = $conn->prepare("SELECT item_name, category, current_stock FROM cutlery_inventory WHERE id = ? AND restaurant_id = ?");
$stmt->bind_param("ii", $cutlery_id, $restaurant_id);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) {
    header("Location: cutlery_inventory.php");
    exit;
}

// Fetch History
$stmt = $conn->prepare("
    SELECT h.*, u.username 
    FROM cutlery_history h 
    LEFT JOIN users u ON h.changed_by = u.id 
    WHERE h.cutlery_id = ? AND h.restaurant_id = ? 
    ORDER BY h.id DESC
");
$stmt->bind_param("ii", $cutlery_id, $restaurant_id);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ==================== EXCEL EXPORT (MUST BE AT THE TOP) ====================
if (isset($_POST['export_excel'])) {
    ob_end_clean();   // Clear any previous output

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Cutlery History');

    // Title
    $sheet->setCellValue('A1', 'CUTLERY HISTORY REPORT - ' . $item_name);
    $sheet->mergeCells('A1:G1');
    $sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $row = 3;

    // Headers
    $headers = ['Date & Time', 'Action', 'Qty Change', 'Stock After', 'Price (Rs)', 'Remarks', 'Done By'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->getFont()->setBold(true);
        $col++;
    }
    $row++;

    // Data
    foreach ($history as $record) {
        $isPositive = in_array($record['action'], ['added', 'restocked']);
        $qty_change = ($isPositive ? '+' : '-') . $record['quantity'];

        $sheet->setCellValue('A' . $row, $record['created_at']);
        $sheet->setCellValue('B' . $row, strtoupper($record['action']));
        $sheet->setCellValue('C' . $row, $qty_change);
        $sheet->setCellValue('D' . $row, $record['stock_after'] ?? '');
        $sheet->setCellValue('E' . $row, $record['price'] ?? '');
        $sheet->setCellValue('F' . $row, $record['remarks'] ?? '');
        $sheet->setCellValue('G' . $row, $record['username'] ?? 'System');

        $row++;
    }

    // Auto size columns
    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Download
    $clean_item = preg_replace('/[^A-Za-z0-9\-_]/', '', $item_name);
    $filename = "Cutlery_History_{$clean_item}_" . date('Y-m-d') . ".xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>History - <?= $item_name ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold text-success">
                <i class="bi bi-clock-history me-2"></i>History - <?= $item_name ?>
            </h4>
            <p class="text-muted">Current Stock: <strong><?= $item['current_stock'] ?></strong></p>
        </div>
        <div>
            <a href="cutlery_inventory.php" class="btn btn-secondary me-2">← Back</a>
            <form method="POST" style="display:inline;">
                <button type="submit" name="export_excel" class="btn btn-success">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </button>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover" id="historyTable">
                <thead class="table-light">
                    <tr>
                        <th>Date & Time</th>
                        <th>Action</th>
                        <th class="text-center">Qty Change</th>
                        <th class="text-center">Stock After</th>
                        <th class="text-end">Price</th>
                        <th>Remarks</th>
                        <th>Done By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $row): 
                        $isPositive = in_array($row['action'], ['added', 'restocked']);
                        $sign = $isPositive ? '+' : '-';
                        $badge = $isPositive ? 'bg-success' : 'bg-danger';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['created_at']) ?></td>
                        <td><span class="badge <?= $badge ?>"><?= strtoupper($row['action']) ?></span></td>
                        <td class="text-center fw-bold <?= $isPositive ? 'text-success' : 'text-danger' ?>">
                            <?= $sign . $row['quantity'] ?>
                        </td>
                        <td class="text-center fw-bold"><?= $row['stock_after'] ?? '-' ?></td>
                        <td class="text-end">
                            <?= $row['price'] > 0 ? 'Rs ' . number_format($row['price'], 2) : '-' ?>
                        </td>
                        <td><?= htmlspecialchars($row['remarks'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['username'] ?? 'System') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>