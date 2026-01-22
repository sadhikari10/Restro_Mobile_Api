<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

date_default_timezone_set('Asia/Kathmandu');

// === SECURITY: Must be superadmin + have session ID ===
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../login.php');
    exit;
}

if (empty($_SESSION['current_restaurant_id'])) {
    header('Location: index.php');
    exit;
}

$restaurant_id = $_SESSION['current_restaurant_id'];

// === FETCH FULL DATA FROM DB USING SESSION ID ===
$stmt = $conn->prepare("
    SELECT name, address, phone_number, expiry_date, is_trial, created_at
    FROM restaurants 
    WHERE id = ? AND chain_id = ?
");
$stmt->bind_param("ii", $restaurant_id, $_SESSION['chain_id']);
$stmt->execute();
$result = $stmt->get_result();
$branch = $result->fetch_assoc();
$stmt->close();

if (!$branch) {
    unset($_SESSION['current_restaurant_id']);
    header('Location: index.php');
    exit;
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($branch['name']) ?> - Branch View</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
</head>
<body class="bg-light">

<!-- ========== NAVBAR ========== -->
<?php include 'navbar.php'; ?>

<div class="container mt-4">
  <div class="row justify-content-center">
    <div class="col-12 col-md-10">

      <!-- Branch Info Card -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0">
            <?= htmlspecialchars($branch['name']) ?>
          </h5>
        </div>
        <div class="card-body">
          <div class="row text-muted small">
            <div class="col-sm-6 mb-2">
              <strong>Restaurant Number:</strong> <?= htmlspecialchars($branch['phone_number']) ?>
            </div>
            <div class="col-sm-6 mb-2">
              <strong>Address:</strong> <?= htmlspecialchars($branch['address'] ?? 'N/A') ?>
            </div>
            <div class="col-sm-6">
              <strong>Expiry (BS):</strong> <?= htmlspecialchars($branch['expiry_date']) ?>
            </div>
            <div class="col-sm-6">
              <strong>Type:</strong>
              <span class="badge bg-<?= $branch['is_trial'] ? 'warning' : 'success' ?>">
                <?= $branch['is_trial'] ? 'Trial' : 'Licensed' ?>
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Admin Dashboard Links -->
      <div class="card shadow-sm">
        <div class="card-body text-center">
          <h5 class="text-success mb-4">Manage This Restaurant</h5>
          <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="menu_items.php" class="btn btn-primary">Menu Items</a>
            <a href="staff_detail.php" class="btn btn-info">Staff</a>
            <a href="take_order.php" class="btn btn-success">Take Order</a>
            <a href="edit_orders.php" class="btn btn-success">Edit Order</a>
            <a href="orders.php" class="btn btn-warning">View Orders</a>
            <a href="charges.php" class="btn btn-secondary">Charges & VAT</a>
            <a href="order_history.php" class="btn btn-success">Order History</a>
            <a href="stock_management.php" class="btn btn-dark">Stock Management</a>
            <a href="general_purchase.php" class="btn btn-dark">General purchase</a>
            <a href="view_general_bills.php" class="btn btn-dark">General purchase history</a>
            <a href="view_stock.php" class="btn btn-dark">View Stock</a>
            <a href="sales_report.php" class="btn btn-dark">Sales Report</a>
            <a href="credit_orders.php" class="btn btn-danger">Credit/Udharo</a>
            <a href="clear_credit.php" class="btn btn-danger">Clear Credit</a>
            <a href="cancel_order.php" class="btn btn-dark">Canceled</a>
          
          </div>
        </div>
      </div>

      <div class="mt-3 text-center">
        <a href="index.php" class="btn btn-outline-secondary">
          Back to Branches
        </a>
      </div>

    </div>
  </div>
</div>

<!-- ========== FOOTER ========== -->
<?php include '../Common/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>