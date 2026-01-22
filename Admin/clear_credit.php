<?php
// Admin/clear_credit.php - Credit Customers & Record Payment (with Excel Export)

session_start();
require '../vendor/autoload.php'; // For PhpOffice\PhpSpreadsheet
require '../Common/connection.php';

// === SECURITY CHECK ===
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

$restaurant_id = (int)$_SESSION['restaurant_id'];

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
    // Clean output buffer to prevent corruption
    if (ob_get_level()) {
        ob_end_clean();
    }

    $clean_name = trim(preg_replace('/[^a-zA-Z0-9\s\-]/', '', $restaurant_name)) ?: 'Restaurant';
    $filename = "Credit_Customers_{$clean_name}.xlsx";

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Title
    $sheet->setCellValue('A1', 'CREDIT CUSTOMERS REPORT');
    $sheet->mergeCells('A1:F1');
    $sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Restaurant & Date
    $sheet->setCellValue('A2', "Restaurant: {$restaurant_name}");
    $sheet->mergeCells('A2:F2');
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

    $sheet->setCellValue('A3', 'Report Date: ' . date('d M Y'));
    $sheet->mergeCells('A3:F3');
    $sheet->getStyle('A3')->getFont()->setItalic(true);

    // Headers
    $headers = ['Name', 'Phone', 'Address', 'Total Consumed (Rs.)', 'Remaining Due (Rs.)'];
    $col = 'A';
    foreach ($headers as $i => $h) {
        $sheet->setCellValue($col . '5', $h);
        $sheet->getStyle($col . '5')->getFont()->setBold(true);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }
    // Header background
    $sheet->getStyle('A5:E5')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FF4CAF50'); // Green
    $sheet->getStyle('A5:E5')->getFont()->getColor()->setARGB('FFFFFFFF');

    // Fetch data for export
    $stmt = $conn->prepare("
        SELECT name, phone, address, total_consumed, remaining_due
        FROM customers
        WHERE restaurant_id = ? AND total_consumed > 0
        ORDER BY remaining_due DESC
    ");
    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $rowNum = 6;
    $grandDue = 0;
    $grandConsumed = 0;

    while ($c = $result->fetch_assoc()) {
        $consumed = (float)$c['total_consumed'];
        $due = (float)$c['remaining_due'];
        $grandConsumed += $consumed;
        $grandDue += $due;

        $dueText = number_format($due, 2);
        if ($due > 0) $dueText .= " (Due)";
        elseif ($due < 0) $dueText .= " (Advance)";
        else $dueText .= " (Cleared)";

        $sheet->setCellValue('A' . $rowNum, $c['name']);
        $sheet->setCellValue('B' . $rowNum, $c['phone'] ?: 'N/A');
        $sheet->setCellValue('C' . $rowNum, $c['address'] ?: 'N/A');
        $sheet->setCellValue('D' . $rowNum, number_format($consumed, 2));
        $sheet->setCellValue('E' . $rowNum, $dueText);

        $rowNum++;
    }
    $stmt->close();

    // Grand Totals
    $sheet->setCellValue('C' . $rowNum, 'GRAND TOTALS');
    $sheet->setCellValue('D' . $rowNum, number_format($grandConsumed, 2));
    $sheet->setCellValue('E' . $rowNum, number_format($grandDue, 2));

    $sheet->getStyle("C{$rowNum}:E{$rowNum}")->getFont()->setBold(true)->setSize(13);
    $sheet->getStyle("C{$rowNum}:E{$rowNum}")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFFFEB3B'); // Yellow highlight

    // Send file
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    header('Expires: 0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// === FETCH CREDIT CUSTOMERS FOR DISPLAY ===
$stmt = $conn->prepare("
    SELECT id, name, phone, address, remaining_due, total_consumed
    FROM customers
    WHERE restaurant_id = ? AND total_consumed > 0
    ORDER BY remaining_due DESC
");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$credit_customers = $stmt->get_result();
$stmt->close();
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Clear Credit - <?= htmlspecialchars($restaurant_name) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="../admin.css" rel="stylesheet">
  <style>
    body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
    .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
    .table { background: white; }
  </style>
</head>
<body>

<div class="container py-5">
  <div class="card shadow-lg">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
      <h4 class="mb-0">Credit Customers - <?= htmlspecialchars($restaurant_name) ?></h4>
      <div>
        <form method="POST" class="d-inline">
          <button type="submit" name="export_excel" class="btn btn-success me-2">
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
          </button>
        </form>
        <a href="dashboard.php" class="btn btn-light btn-sm">&larr; Back</a>
      </div>
    </div>
    <div class="card-body">
      <?php if ($credit_customers->num_rows === 0): ?>
          <div class="alert alert-info text-center">
            <strong>No customers have used credit yet.</strong>
          </div>
      <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead class="table-dark">
                <tr>
                  <th>Name</th>
                  <th>Phone</th>
                  <th>Address</th>
                  <th>Total Consumed (Rs.)</th>
                  <th>Remaining Due (Rs.)</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($c = $credit_customers->fetch_assoc()): ?>
                  <?php 
                    $due = (float)$c['remaining_due'];
                    $consumed = (float)$c['total_consumed'];
                    $dueClass = $due > 0 ? 'text-danger' : ($due < 0 ? 'text-success' : 'text-muted');
                    $dueLabel = $due > 0 ? '' : ($due < 0 ? ' (Advance)' : ' (Cleared)');
                  ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                    <td><?= htmlspecialchars($c['phone'] ?: 'N/A') ?></td>
                    <td><?= htmlspecialchars($c['address'] ?: 'N/A') ?></td>
                    <td class="fw-bold text-primary"><?= number_format($consumed, 2) ?></td>
                    <td class="fw-bold <?= $dueClass ?>">
                      <?= number_format($due, 2) ?><?= $dueLabel ?>
                    </td>
                    <td>
                      <button class="btn btn-sm btn-success me-2 mark-paid-btn"
                              data-customer-id="<?= $c['id'] ?>"
                              data-customer-name="<?= htmlspecialchars($c['name']) ?>"
                              data-remaining-due="<?= $due ?>">
                        Mark Paid
                      </button>
                      <button class="btn btn-sm btn-primary history-btn"
                              data-customer-id="<?= $c['id'] ?>">
                        History
                      </button>
                    </td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modals and JavaScript remain unchanged -->
<div class="modal fade" id="markPaidModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Mark Payment for <span id="paidCustomerName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="markPaidForm">
          <input type="hidden" id="paidCustomerId">
          <div class="mb-3">
            <label for="paidAmount" class="form-label fw-bold">Amount (Rs.)</label>
            <input type="number" class="form-control" id="paidAmount" min="0" step="0.01" placeholder="0.00" required>
            <small class="form-text text-muted">Current Due: Rs. <span id="paidRemainingDue">0.00</span></small>
          </div>
          <div class="mb-3">
            <label for="paidMethod" class="form-label">Payment Method</label>
            <select class="form-select" id="paidMethod">
              <option value="cash">Cash</option>
              <option value="online">Online</option>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmPaid">Continue</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="confirmPaidModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Customer: <strong id="confirmCustomerName"></strong></p>
        <p>Payment Amount: Rs. <strong id="confirmAmount"></strong></p>
        <p>Payment Method: <strong id="confirmMethod"></strong></p>
        <p>New Remaining Due: Rs. <strong id="confirmNewDue"></strong></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="finalConfirmPaid">Confirm Payment</button>
      </div>
    </div>
  </div>
</div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
  <div id="successToast" class="toast align-items-center text-white bg-success border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body"><strong>✓ Payment successfully recorded!</strong></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Open Mark Paid modal
document.querySelectorAll('.mark-paid-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const id = this.dataset.customerId;
    const name = this.dataset.customerName;
    const due = parseFloat(this.dataset.remainingDue) || 0;

    document.getElementById('paidCustomerId').value = id;
    document.getElementById('paidCustomerName').textContent = name;
    document.getElementById('paidRemainingDue').textContent = due.toFixed(2);
    document.getElementById('paidAmount').value = '';
    document.getElementById('paidMethod').value = 'cash';

    if (due > 0) {
      document.getElementById('paidAmount').value = due.toFixed(2);
    }

    new bootstrap.Modal(document.getElementById('markPaidModal')).show();
  });
});

document.getElementById('confirmPaid').addEventListener('click', function() {
  const amount = parseFloat(document.getElementById('paidAmount').value);
  const method = document.getElementById('paidMethod').value;
  const currentDue = parseFloat(document.getElementById('paidRemainingDue').textContent) || 0;

  if (isNaN(amount) || amount <= 0) {
    alert('Please enter a valid positive amount');
    return;
  }

  const newDue = currentDue - amount;

  document.getElementById('confirmCustomerName').textContent = document.getElementById('paidCustomerName').textContent;
  document.getElementById('confirmAmount').textContent = amount.toFixed(2);
  document.getElementById('confirmMethod').textContent = method.charAt(0).toUpperCase() + method.slice(1);
  document.getElementById('confirmNewDue').textContent = newDue.toFixed(2);

  bootstrap.Modal.getInstance(document.getElementById('markPaidModal')).hide();
  new bootstrap.Modal(document.getElementById('confirmPaidModal')).show();
});

document.getElementById('finalConfirmPaid').addEventListener('click', function() {
  const customerId = document.getElementById('paidCustomerId').value;
  const amount = document.getElementById('confirmAmount').textContent;
  const method = document.getElementById('confirmMethod').textContent.toLowerCase();

  const formData = new FormData();
  formData.append('customer_id', customerId);
  formData.append('amount', amount);
  formData.append('payment_method', method);

  fetch('record_payment.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      const toast = new bootstrap.Toast(document.getElementById('successToast'));
      toast.show();
      setTimeout(() => location.reload(), 2500);
    } else {
      alert('Error: ' + (data.error || 'Failed to record payment'));
    }
  })
  .catch(err => {
    console.error(err);
    alert('Network error. Please try again.');
  });
});

document.querySelectorAll('.history-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const customerId = this.dataset.customerId;
    window.location.href = 'customer_history.php?customer_id=' + customerId;
  });
});
</script>

<?php include '../Common/footer.php'; ?>
</body>
</html>