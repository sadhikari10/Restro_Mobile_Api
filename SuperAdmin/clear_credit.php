<?php
session_start();
require '../vendor/autoload.php';
require '../Common/connection.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../login.php');
    exit;
}

if (empty($_SESSION['current_restaurant_id'])) {
    header('Location: index.php');
    exit;
}

$restaurant_id = $_SESSION['current_restaurant_id'];

// ==================== EXCEL EXPORT BLOCK ====================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    
    while (ob_get_level()) {
        ob_end_clean();
    }

    $stmt = $conn->prepare("
        SELECT name, phone, address, total_consumed, remaining_due
        FROM customers
        WHERE restaurant_id = ? AND total_consumed > 0
        ORDER BY remaining_due DESC
    ");
    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $grand_total_due = 0;
    $grand_total_consumed = 0;
    $rows = [];

    while ($c = $result->fetch_assoc()) {
        $consumed = (float)$c['total_consumed'];
        $due = (float)$c['remaining_due'];
        
        $grand_total_consumed += $consumed;
        $grand_total_due += $due;

        $dueText = number_format($due, 2);
        if ($due > 0) {
            $dueText .= '';
        } elseif ($due < 0) {
            $dueText .= ' (Advance)';
        } else {
            $dueText .= ' (Cleared)';
        }

        $rows[] = [
            $c['name'],
            $c['phone'] ?: 'N/A',
            $c['address'] ?: 'N/A',
            number_format($consumed, 2),
            $dueText
        ];
    }
    $stmt->close();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'Credit Customers List');
    $sheet->mergeCells('A1:E1');
    $sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

    $headers = ['Name', 'Phone', 'Address', 'Total Consumed (Rs.)', 'Remaining Due (Rs.)'];
    $sheet->fromArray($headers, null, 'A3');

    $headerStyle = $sheet->getStyle('A3:E3');
    $headerStyle->getFont()->setBold(true);
    $headerStyle->getFont()->getColor()->setARGB('FFFFFFFF');
    $headerStyle->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('1a73e8');

    $sheet->fromArray($rows, null, 'A4');

    $last_row = 4 + count($rows);
    $sheet->setCellValue("D$last_row", 'GRAND TOTAL CONSUMED');
    $sheet->setCellValue("E$last_row", 'GRAND TOTAL DUE');
    $sheet->setCellValue("D" . ($last_row + 1), number_format($grand_total_consumed, 2));
    $sheet->setCellValue("E" . ($last_row + 1), number_format($grand_total_due, 2));
    
    $sheet->getStyle("D$last_row:E" . ($last_row + 1))->getFont()->setBold(true);
    $sheet->getStyle("D$last_row:E$last_row")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('fff3cd');

    foreach (range('A', 'E') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Credit_Customers.xlsx"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    header('Expires: 0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
// ==================== END OF EXPORT BLOCK ====================

// Fetch data for display
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
  <title>Clear Credit</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
    .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
    .table { background: white; }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container py-5">
  <div class="card shadow-lg">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
      <h4 class="mb-0">Credit Customers</h4>
      <div>
        <a href="view_branch.php" class="btn btn-light btn-sm me-2">
          <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <a href="clear_credit.php?export=excel" class="btn btn-success btn-sm">
          <i class="bi bi-file-earmark-excel me-1"></i> Export to Excel
        </a>
      </div>
    </div>
    <div class="card-body">
      <?php if ($credit_customers->num_rows === 0): ?>
          <div class="alert alert-info">No customers have used credit yet.</div>
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
                  ?>
                  <tr>
                    <td><?= htmlspecialchars($c['name']) ?></td>
                    <td><?= htmlspecialchars($c['phone'] ?: 'N/A') ?></td>
                    <td><?= htmlspecialchars($c['address'] ?: 'N/A') ?></td>
                    <td class="fw-bold text-primary"><?= number_format($consumed, 2) ?></td>
                    <td class="fw-bold <?= $dueClass ?>">
                      <?= number_format($due, 2) ?>
                      <?= $due > 0 ? '' : ($due < 0 ? ' (Advance)' : ' (Cleared)') ?>
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

<!-- Modals and Scripts remain unchanged -->
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
            <label for="paidAmount" class="form-label">Amount (Rs.)</label>
            <input type="number" class="form-control" id="paidAmount" min="0" step="0.01" required>
            <small class="form-text text-muted">Current Due: Rs. <span id="paidRemainingDue">0.00</span></small>
          </div>
          <div class="mb-3">
            <label for="paidMethod" class="form-label">Payment Method</label>
            <select class="form-select" id="paidMethod" required>
              <option value="cash">Cash</option>
              <option value="online">Online</option>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmPaid">Confirm</button>
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
document.querySelectorAll('.mark-paid-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const id = this.dataset.customerId;
    const name = this.dataset.customerName;
    const due = parseFloat(this.dataset.remainingDue);

    document.getElementById('paidCustomerId').value = id;
    document.getElementById('paidCustomerName').textContent = name;
    document.getElementById('paidRemainingDue').textContent = due.toFixed(2);
    document.getElementById('paidAmount').value = due > 0 ? due : '';
    document.getElementById('paidMethod').value = 'cash';

    new bootstrap.Modal(document.getElementById('markPaidModal')).show();
  });
});

document.getElementById('confirmPaid').addEventListener('click', function() {
  const amount = parseFloat(document.getElementById('paidAmount').value);
  const method = document.getElementById('paidMethod').value;
  const currentDue = parseFloat(document.getElementById('paidRemainingDue').textContent);

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
      alert('Error: ' + (data.error || 'Unknown error'));
    }
  })
  .catch(err => {
    console.error(err);
    alert('Network error. Check console.');
  });
});

document.querySelectorAll('.history-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const customerId = this.dataset.customerId;
    window.location.href = 'customer_history.php?customer_id=' + customerId;
  });
});
</script>
</body>
</html>