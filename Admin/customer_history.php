<?php
// Admin/customer_history.php - Customer Transaction History (Fixed Excel Export with Entered By)

ob_start();  // Start buffering immediately
session_start();
require '../vendor/autoload.php';
require '../Common/connection.php';

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

$restaurant_id = (int)$_SESSION['restaurant_id'];
$customer_id = (int)($_GET['customer_id'] ?? 0);

if ($customer_id <= 0) {
    ob_end_clean();
    die('Invalid customer');
}

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

// ==================== EXCEL EXPORT ====================
if (isset($_POST['export_excel'])) {
    ob_end_clean();  // Clear everything before sending file

    // Fetch customer details first (needed for filename and header)
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

    $clean_name = trim(preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $restaurant_name)) ?: 'Restaurant';
    $clean_customer = trim(preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $customer['name'])) ?: 'Customer';
    $filename = "Customer_History_{$clean_customer}_{$clean_name}.xlsx";  // Fixed: .xlsx

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Title
    $sheet->setCellValue('A1', 'CUSTOMER TRANSACTION HISTORY');
    $sheet->mergeCells('A1:H1');
    $sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Customer & Restaurant
    $sheet->setCellValue('A2', "Customer: {$customer['name']}");
    $sheet->mergeCells('A2:D2');
    $sheet->getStyle('A2')->getFont()->setBold(true);

    $sheet->setCellValue('E2', "Restaurant: {$restaurant_name}");
    $sheet->mergeCells('E2:H2');
    $sheet->getStyle('E2')->getFont()->setBold(true);

    // Summary
    $sheet->setCellValue('A3', "Phone: " . ($customer['phone'] ?: 'N/A'));
    $sheet->setCellValue('C3', "Address: " . ($customer['address'] ?: 'N/A'));
    $sheet->setCellValue('E3', "Total Consumed: Rs. " . number_format($customer['total_consumed'], 2));
    $sheet->setCellValue('F3', "Total Paid: Rs. " . number_format($customer['total_paid'], 2));
    $sheet->setCellValue('G3', "Remaining Due: Rs. " . number_format($customer['remaining_due'], 2));

    // Headers
    $headers = ['Date (BS)', 'Type', 'Amount (Rs)', 'Discount (Rs)', 'Payment Method', 'Notes', 'Entered By', 'Bill Details'];
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . '5', $h);
        $sheet->getStyle($col . '5')->getFont()->setBold(true);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }
    $sheet->getStyle('A5:H5')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFD9EAD3');

    // Fetch transactions with created_by
    $stmt = $conn->prepare("
        SELECT type, amount, discount, payment_method, notes, transaction_bs_date, bill_details, created_by
        FROM customer_transactions
        WHERE customer_id = ? AND restaurant_id = ?
        ORDER BY transaction_bs_date DESC
    ");
    $stmt->bind_param("ii", $customer_id, $restaurant_id);
    $stmt->execute();
    $transactions_export = $stmt->get_result();

    // Collect unique created_by IDs
    $created_by_ids = [];
    while ($t = $transactions_export->fetch_assoc()) {
        $created_by_ids[$t['created_by']] = true;
    }
    $transactions_export->data_seek(0);  // Reset result pointer

    // Fetch usernames for those IDs
    $usernames = [];
    if (!empty($created_by_ids)) {
        $ids = array_keys($created_by_ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt_users = $conn->prepare("SELECT id, username FROM users WHERE id IN ($placeholders)");
        $stmt_users->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt_users->execute();
        $result_users = $stmt_users->get_result();
        while ($u = $result_users->fetch_assoc()) {
            $usernames[$u['id']] = $u['username'];
        }
        $stmt_users->close();
    }

    // Transactions
    $rowNum = 6;
    while ($t = $transactions_export->fetch_assoc()) {
        $isConsumption = $t['type'] === 'consumption';
        $typeLabel = $isConsumption ? 'Consumed' : 'Paid';

        $sheet->setCellValue('A' . $rowNum, $t['transaction_bs_date']);
        $sheet->setCellValue('B' . $rowNum, $typeLabel);
        $sheet->setCellValue('C' . $rowNum, number_format($t['amount'], 2));
        $sheet->setCellValue('D' . $rowNum, number_format($t['discount'], 2));
        $sheet->setCellValue('E' . $rowNum, $t['payment_method'] === 'credit' ? 'Credit' :
            ($t['payment_method'] === 'cash' ? 'Cash' :
            ($t['payment_method'] === 'online' ? 'Online' : $t['payment_method'])));
        $sheet->setCellValue('F' . $rowNum, $t['notes'] ?? '');

        // Entered By
        $entered_by = isset($usernames[$t['created_by']]) ? $usernames[$t['created_by']] : 'Unknown';
        $sheet->setCellValue('G' . $rowNum, $entered_by);

        $details = '';
        if ($isConsumption && !empty($t['bill_details'])) {
            $bill = json_decode($t['bill_details'], true);
            if (is_array($bill) && !empty($bill['items'])) {
                foreach ($bill['items'] as $item) {
                    $details .= $item['name'] . " × " . $item['qty'];
                    if (!empty($item['notes'])) $details .= " (" . $item['notes'] . ")";
                    $details .= "\n";
                }
                if (!empty($bill['discount']) && $bill['discount'] > 0) {
                    $details .= "Discount: Rs. " . number_format($bill['discount'], 2) . "\n";
                }
                $details .= "Net Total: Rs. " . number_format($bill['net_total'], 2);
                if (!empty($bill['paid_today']) && $bill['paid_today'] > 0) {
                    $details .= "\nPaid Today: Rs. " . number_format($bill['paid_today'], 2);
                }
            }
        }
        $sheet->setCellValue('H' . $rowNum, $details);
        $sheet->getRowDimension($rowNum)->setRowHeight(-1);  // Auto height

        $rowNum++;
    }
    $stmt->close();

    // Send file
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . urlencode($filename) . '"');
    header('Cache-Control: max-age=0');

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

// ==================== HTML PART ====================
// (Remains unchanged, except add created_by to transactions query if you want to display it in HTML too, but per request, only Excel)


// Fetch customer details
$stmt = $conn->prepare("
    SELECT name, phone, address, total_consumed, total_paid, remaining_due
    FROM customers
    WHERE id = ? AND restaurant_id = ?
");
$stmt->bind_param("ii", $customer_id, $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    ob_end_clean();
    die('Customer not found');
}
$customer = $result->fetch_assoc();
$stmt->close();

// Fetch transactions (for HTML, no created_by needed per request)
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

ob_end_flush();  // Flush buffer for HTML
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Transaction History - <?= htmlspecialchars($customer['name']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="../admin.css" rel="stylesheet">
  <style>
    body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
    .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
    .table { background: white; }
    .consumption { background-color: #fff3cd; }
    .payment { background-color: #d4edda; }
  </style>
</head>
<body>

<div class="container py-5">
  <div class="card shadow-lg">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
      <h4 class="mb-0">
        Transaction History - <?= htmlspecialchars($customer['name']) ?>
      </h4>
      <div>
        <form method="POST" style="display:inline;">
          <button type="submit" name="export_excel" class="btn btn-success me-2">
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
          </button>
        </form>
        <a href="clear_credit.php" class="btn btn-light btn-sm">&larr; Back to Credit Customers</a>
      </div>
    </div>
    <div class="card-body">
      <!-- Rest of your HTML remains exactly the same -->
      <div class="row mb-4">
        <div class="col-md-4">
          <strong>Phone:</strong> <?= htmlspecialchars($customer['phone'] ?: 'N/A') ?>
        </div>
        <div class="col-md-4">
          <strong>Address:</strong> <?= htmlspecialchars($customer['address'] ?: 'N/A') ?>
        </div>
        <div class="col-md-4 text-end">
          <strong>Remaining Due:</strong>
          <span class="fs-5 fw-bold <?= $customer['remaining_due'] > 0 ? 'text-danger' : ($customer['remaining_due'] < 0 ? 'text-success' : 'text-success') ?>">
            Rs. <?= number_format($customer['remaining_due'], 2) ?>
          </span>
        </div>
      </div>

      <div class="row mb-4 text-muted small">
        <div class="col"><strong>Total Consumed:</strong> Rs. <?= number_format($customer['total_consumed'], 2) ?></div>
        <div class="col"><strong>Total Paid:</strong> Rs. <?= number_format($customer['total_paid'], 2) ?></div>
      </div>

      <?php if ($transactions->num_rows === 0): ?>
        <div class="alert alert-info text-center">No transactions recorded yet.</div>
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
                        <strong>Items Ordered:</strong><br>
                        <?php foreach ($bill['items'] as $item): ?>
                          &middot; <?= htmlspecialchars($item['name']) ?> × <?= $item['qty'] ?>
                          <?= !empty($item['notes']) ? ' <em>(' . htmlspecialchars($item['notes']) . ')</em>' : '' ?><br>
                        <?php endforeach; ?>
                        <?php if (!empty($bill['discount']) && $bill['discount'] > 0): ?>
                          <em>Discount: Rs. <?= number_format($bill['discount'], 2) ?></em><br>
                        <?php endif; ?>
                        <strong>Net Total: Rs. <?= number_format($bill['net_total'], 2) ?></strong>
                        <?php if (!empty($bill['paid_today']) && $bill['paid_today'] > 0): ?>
                          <br><em>Paid Today: Rs. <?= number_format($bill['paid_today'], 2) ?></em>
                        <?php endif; ?>
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

<?php include '../Common/footer.php'; ?>
</body>
</html>