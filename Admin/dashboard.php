<?php
// Admin/dashboard.php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

// SESSION CHECK (updated)
if (empty($_SESSION['logged_in']) || empty($_SESSION['restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($_SESSION['restaurant_name'] ?? 'Admin Dashboard') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="admin.css" rel="stylesheet">
</head>
<body class="dashboard-body">
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light header">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <?= htmlspecialchars($_SESSION['restaurant_name'] ?? 'Restaurant Admin') ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../Common/logout.php">Logout (<?= htmlspecialchars($_SESSION['username']) ?>)</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8">
                <div class="welcome-card text-center">
                    <h3 class="mb-4 text-success">Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</h3>
                    <p class="lead text-muted">Manage your restaurant's menu, staff, and stock with ease.</p>

                    <?php if ($_SESSION['role'] === 'admin'): ?>
                    <div class="d-flex justify-content-center gap-3 flex-wrap mt-3">
                        <a href="menu_items.php" class="btn btn-primary btn-custom">🍽️ Menu Items</a>
                        <a href="staff_detail.php" class="btn btn-info btn-custom">👨‍🍳 Staff Detail</a>
                        <a href="take_order.php" class="btn btn-primary btn-custom">📋 Take Order</a>
                        <a href="edit_orders.php" class="btn btn-warning btn-custom">✏️ Edit Order</a>
                        <a href="orders.php" class="btn btn-info btn-custom">🧾 View Orders</a>
                        <a href="charges.php" class="btn btn-warning btn-custom">💰 Charges & VAT</a>
                        <a href="order_history.php" class="btn btn-success btn-custom">📜 Order History</a>
                        <a href="stock_management.php" class="btn btn-secondary btn-custom">Inventory</a>			                       
			<a href="view_stock.php" class="btn btn-dark">Inventory History</a>
                        <a href="general_purchase.php" class="btn btn-dark">General Purchase</a>
                        <a href="view_general_bills.php" class="btn btn-dark">General Purchase History</a>
                        <a href="sales_report.php" class="btn btn-dark">Sales Report</a>
                        <a href="credit_orders.php" class="btn btn-danger">Credit/Udharo</a>
                        <a href="clear_credit.php" class="btn btn-danger">Clear credit</a>
                    </div>
                    <?php else: ?>
                    <p class="text-muted">Your role (<?= htmlspecialchars($_SESSION['role']) ?>) has limited access. Contact admin for more features.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php 
        include '../Common/footer.php';
    ?>
</body>
</html>
