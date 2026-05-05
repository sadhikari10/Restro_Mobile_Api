<?php
// SuperAdmin/booking_history.php
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

// ==================== EXPORT TO EXCEL ====================
if (isset($_POST['export_excel'])) {
    require '../vendor/autoload.php'; // Make sure PhpSpreadsheet is installed

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Header
    $sheet->setCellValue('A1', 'Booking History Report');
    $sheet->setCellValue('A3', 'Guest Name');
    $sheet->setCellValue('B3', 'Package Name');
    $sheet->setCellValue('C3', 'ID Type');
    $sheet->setCellValue('D3', 'Mobile');
    $sheet->setCellValue('E3', 'Check-in');
    $sheet->setCellValue('F3', 'Check-out');
    $sheet->setCellValue('G3', 'Duration');
    $sheet->setCellValue('H3', 'Total Amount');
    $sheet->setCellValue('I3', 'Advance Paid');
    $sheet->setCellValue('J3', 'Remaining');
    $sheet->setCellValue('K3', 'Status');
    $sheet->setCellValue('L3', 'Created At');

    // Fetch all bookings
    $stmt = $conn->prepare("SELECT * FROM booking WHERE restaurant_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $row = 4;
    foreach ($bookings as $b) {
        $remaining = $b['total_amount'] - $b['advance_paid'];
        $sheet->setCellValue('A' . $row, $b['guest_name']);
        $sheet->setCellValue('B' . $row, $b['room_category']);
        $sheet->setCellValue('C' . $row, $b['id_type']);
        $sheet->setCellValue('D' . $row, $b['mobile_number']);
        $sheet->setCellValue('E' . $row, $b['checkin_date']);
        $sheet->setCellValue('F' . $row, $b['checkout_date']);
        $sheet->setCellValue('G' . $row, $b['duration_of_stay']);
        $sheet->setCellValue('H' . $row, $b['total_amount']);
        $sheet->setCellValue('I' . $row, $b['advance_paid']);
        $sheet->setCellValue('J' . $row, $remaining);
        $sheet->setCellValue('K' . $row, $b['status']);
        $sheet->setCellValue('L' . $row, $b['created_at']);
        $row++;
    }

    // Styling
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A3:L3')->getFont()->setBold(true);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Booking_History_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// Fetch All Bookings for Display
$stmt = $conn->prepare("SELECT * FROM booking WHERE restaurant_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking History</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-success"><i class="bi bi-clock-history"></i> Booking History</h2>
        <div>
            <a href="view_branch.php" class="btn btn-secondary me-2">Back</a>
            <form method="POST" style="display:inline;">
                <button type="submit" name="export_excel" class="btn btn-success">
                    <i class="bi bi-file-earmark-excel"></i> Export to Excel
                </button>
            </form>
        </div>
    </div>

    <?php if($success_msg): ?><div class="alert alert-success"><?= $success_msg ?></div><?php endif; ?>
    <?php if($error_msg): ?><div class="alert alert-danger"><?= $error_msg ?></div><?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <table class="table table-hover table-bordered" id="historyTable">
                <thead class="table-dark">
                    <tr>
                        <th>Guest Name</th>
                        <th>Package</th>
                        <th>Mobile</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Duration</th>
                        <th>Total</th>
                        <th>Advance</th>
                        <th>Remaining</th>
                        <th>Status</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b): 
                        $remaining = $b['total_amount'] - $b['advance_paid'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($b['guest_name']) ?></td>
                        <td><?= htmlspecialchars($b['room_category']) ?></td>
                        <td><?= htmlspecialchars($b['mobile_number']) ?></td>
                        <td><?= $b['checkin_date'] ?></td>
                        <td><?= $b['checkout_date'] ?></td>
                        <td><?= $b['duration_of_stay'] ?> nights</td>
                        <td>Rs. <?= number_format($b['total_amount'], 2) ?></td>
                        <td>Rs. <?= number_format($b['advance_paid'], 2) ?></td>
                        <td class="text-danger">Rs. <?= number_format($remaining, 2) ?></td>
                        <td>
                            <span class="badge bg-<?= $b['status'] == 'checked_out' ? 'success' : ($b['status'] == 'checked_in' ? 'primary' : 'warning') ?>">
                                <?= ucfirst($b['status']) ?>
                            </span>
                        </td>
                        <td><?= $b['created_at'] ?></td>
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