<?php
session_start();
require '../Common/connection.php'; // Adjust path if connection.php is in Common/

// SESSION CHECK (for staff only)
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'staff' || empty($_SESSION['restaurant_id'])) {
    header('Location: ../common/login.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($_SESSION['restaurant_name'] ?? 'Staff Dashboard') ?> - Staff Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="staff.css" rel="stylesheet"> <!-- Assuming admin.css is in admin/ -->
</head>
<body class="dashboard-body">
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light header">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <?= htmlspecialchars($_SESSION['restaurant_name'] ?? 'Restaurant') ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../common/logout.php">Logout (<?= htmlspecialchars($_SESSION['username']) ?>)</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8">
                <div class="welcome-card text-center">
                    <h3 class="mb-4 text-success">Welcome to <?= htmlspecialchars($_SESSION['restaurant_name'] ?? 'your restaurant') ?>, <?= htmlspecialchars($_SESSION['username']) ?>!</h3>
                    <p class="lead text-muted">You are logged in as Staff. Use the menu to manage orders.</p>
                    <!-- Staff-specific links: Take Order, Edit Order, and Meal Served -->
                    <div class="d-flex justify-content-center gap-3 flex-wrap mt-4">
                        <a href="orders.php" class="btn btn-primary btn-custom">📋 Take Order</a>
                        <a href="edit_orders.php" class="btn btn-warning btn-custom">✏️ Edit Order</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php 
        include('../Common/footer.php');
    ?>
</body>
</html>