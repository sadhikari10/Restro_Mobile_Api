<?php
session_start();
require '../vendor/autoload.php';
require '../Common/connection.php';


use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin' || empty($_SESSION['current_restaurant_id'])) {
    header('Location: ../login.php');
    exit;
}

$restaurant_id = (int)$_SESSION['current_restaurant_id'];
$customer_id = (int)($_GET['customer_id'] ?? 0);

if ($customer_id <= 0) {
    die('Invalid customer');
}

$stmt = $conn->prepare("
    SELECT name, phone, address, total_consumed, total_paid, remaining_due
    FROM customers
    WHERE id = ? AND restaurant_id = ?
");
$stmt->bind_param("ii", $customer_id, $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die('Customer not found');
}
$customer = $result->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("
    SELECT type, amount, discount, payment_method, notes, transaction_bs_date, bill_details
    FROM customer_transactions
    WHERE customer_id = ? AND restaurant_id = ?
    ORDER BY transaction_bs_date DESC
");
$stmt->bind_param("ii", $customer_id, $restaurant_id);
$stmt->execute();
$transactions = $stmt->get_result();
$stmt->close();

if (isset($_GET['export']) && $_GET['export'] === 'excel') {

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'Transaction History - ' . $customer['name']);
    $sheet->mergeCells('A1:G1');
    $sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

    $sheet->setCellValue('A3', 'Customer Name');
    $sheet->setCellValue('B3', $customer['name']);
    $sheet->setCellValue('A4', 'Phone');
    $sheet->setCellValue('B4', $customer['phone'] ?: 'N/A');
    $sheet->setCellValue('A5', 'Address');
    $sheet->setCellValue('B5', $customer['address'] ?: 'N/A');
    $sheet->setCellValue('A6', 'Remaining Due (Rs.)');
    $sheet->setCellValue('B6', number_format($customer['remaining_due'], 2));
    $sheet->setCellValue('A7', 'Total Consumed (Rs.)');
    $sheet->setCellValue('B7', number_format($customer['total_consumed'], 2));
    $sheet->setCellValue('A8', 'Total Paid (Rs.)');
    $sheet->setCellValue('B8', number_format($customer['total_paid'], 2));

    $headers = ['Date (BS)', 'Type', 'Amount (Rs.)', 'Discount (Rs.)', 'Payment Method', 'Notes', 'Items'];
    $sheet->fromArray($headers, null, 'A10');

    $headerStyle = $sheet->getStyle('A10:G10');
    $headerStyle->getFont()->setBold(true);
    $headerStyle->getFont()->getColor()->setARGB('FFFFFFFF');
    $headerStyle->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('1a73e8');

    $rowIndex = 11;
    $transactions->data_seek(0);
    while ($t = $transactions->fetch_assoc()) {
        $isConsumption = $t['type'] === 'consumption';
        $typeLabel = $isConsumption ? 'Consumed' : 'Paid';

        $itemsText = '';
        if ($isConsumption && !empty($t['bill_details'])) {
            $bill = json_decode($t['bill_details'], true);
            if (is_array($bill) && !empty($bill['items'])) {
                foreach ($bill['items'] as $item) {
                    $itemsText .= $item['name'] . ' × ' . $item['qty'];
                    if (!empty($item['notes'])) {
                        $itemsText .= ' (' . $item['notes'] . ')';
                    }
                    $itemsText .= "\n";
                }
                if ($bill['discount'] > 0) {
                    $itemsText .= 'Discount: Rs. ' . number_format($bill['discount'], 2) . "\n";
                }
                $itemsText .= 'Net Total: Rs. ' . number_format($bill['net_total'], 2);
            }
        }

        $sheet->setCellValue("A$rowIndex", $t['transaction_bs_date']);
        $sheet->setCellValue("B$rowIndex", $typeLabel);
        $sheet->setCellValue("C$rowIndex", number_format($t['amount'], 2));
        $sheet->setCellValue("D$rowIndex", number_format($t['discount'], 2));
        $sheet->setCellValue("E$rowIndex", $t['payment_method'] === 'credit' ? 'Credit' : ($t['payment_method'] === 'cash' ? 'Cash' : ($t['payment_method'] === 'online' ? 'Online' : $t['payment_method'])));
        $sheet->setCellValue("F$rowIndex", $t['notes']);
        $sheet->setCellValue("G$rowIndex", $itemsText);

        $sheet->getStyle("G$rowIndex")->getAlignment()->setWrapText(true);
        $rowIndex++;
    }

    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $customer['name']) . '_History.xlsx"');
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
  <title>Transaction History - <?= htmlspecialchars($customer['name']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
    .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
    .table { background: white; }
    .consumption { background-color: #fff3cd; }
    .payment { background-color: #d4edda; }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container py-5">
  <div class="card shadow-lg">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
      <h4 class="mb-0">
        Transaction History - <?= htmlspecialchars($customer['name']) ?>
      </h4>
      <div class="d-flex gap-2">
        <a href="clear_credit.php" class="btn btn-light btn-sm">&larr; Back</a>
        <a href="customer_history.php?customer_id=<?= $customer_id ?>&export=excel" class="btn btn-success btn-sm">
          Export to Excel
        </a>
      </div>
    </div>
    <div class="card-body">
      <div class="row mb-4">
        <div class="col-md-4">
          <strong>Phone:</strong> <?= htmlspecialchars($customer['phone'] ?: 'N/A') ?>
        </div>
        <div class="col-md-4">
          <strong>Address:</strong> <?= htmlspecialchars($customer['address'] ?: 'N/A') ?>
        </div>
        <div class="col-md-4 text-end">
          <strong>Remaining Due:</strong>
          <span class="fs-5 fw-bold <?= $customer['remaining_due'] > 0 ? 'text-danger' : 'text-success' ?>">
            Rs. <?= number_format($customer['remaining_due'], 2) ?>
          </span>
        </div>
      </div>

      <div class="row mb-3 text-muted small">
        <div class="col"><strong>Total Consumed:</strong> Rs. <?= number_format($customer['total_consumed'], 2) ?></div>
        <div class="col"><strong>Total Paid:</strong> Rs. <?= number_format($customer['total_paid'], 2) ?></div>
      </div>

      <?php if ($transactions->num_rows === 0): ?>
        <div class="alert alert-info">No transactions yet.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Discount</th>
                <th>Method</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($t = $transactions->fetch_assoc()): ?>
                <?php
                  $isConsumption = $t['type'] === 'consumption';
                  $rowClass = $isConsumption ? 'consumption' : 'payment';
                  $typeLabel = $isConsumption ? 'Consumed' : 'Paid';
                  $amountColor = $isConsumption ? 'text-danger' : 'text-success';
                ?>
                <tr class="<?= $rowClass ?>">
                  <td><?= htmlspecialchars($t['transaction_bs_date']) ?></td>
                  <td><strong><?= $typeLabel ?></strong></td>
                  <td class="fw-bold <?= $amountColor ?>">Rs. <?= number_format($t['amount'], 2) ?></td>
                  <td>Rs. <?= number_format($t['discount'], 2) ?></td>
                  <td>
                    <?= $t['payment_method'] === 'credit' ? 'Credit' :
                        ($t['payment_method'] === 'cash' ? 'Cash' :
                        ($t['payment_method'] === 'online' ? 'Online' : htmlspecialchars($t['payment_method']))) ?>
                  </td>
                  <td><?= htmlspecialchars($t['notes']) ?></td>
                </tr>
                <?php if ($isConsumption && !empty($t['bill_details'])): ?>
                  <?php
                    $bill = json_decode($t['bill_details'], true);
                    if (is_array($bill) && !empty($bill['items'])):
                  ?>
                    <tr class="<?= $rowClass ?>">
                      <td colspan="6" class="ps-5 small">
                        <strong>Items:</strong>
                        <?php foreach ($bill['items'] as $item): ?>
                          <?= htmlspecialchars($item['name']) ?> × <?= $item['qty'] ?>
                          <?= !empty($item['notes']) ? ' (' . htmlspecialchars($item['notes']) . ')' : '' ?><br>
                        <?php endforeach; ?>
                        <?php if ($bill['discount'] > 0): ?>
                          <em>Discount: Rs. <?= number_format($bill['discount'], 2) ?></em><br>
                        <?php endif; ?>
                        <em>Net Total: Rs. <?= number_format($bill['net_total'], 2) ?></em>
                      </td>
                    </tr>
                  <?php endif; ?>
                <?php endif; ?>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>