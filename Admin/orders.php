<?php
// Admin/orders.php (View Orders - list preparing orders)
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';


// SESSION CHECK
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

// FETCH ONLY PREPARING ORDERS
$query = "SELECT * FROM orders WHERE restaurant_id = ? AND status = 'preparing' ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $_SESSION['restaurant_id']);
$stmt->execute();
$result = $stmt->get_result();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($_SESSION['restaurant_name'] ?? 'Orders') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="admin.css" rel="stylesheet">
    <style>
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .content { flex: 1; }
        footer { background: #000; color: #fff; text-align: center; padding: 1rem; font-size: 0.9rem; }
        footer a { color: #fff; text-decoration: none; font-weight: 600; }
        footer a:hover { text-decoration: underline; }
        .back-btn { margin-bottom: 1rem; }
        @media (max-width: 576px) { footer { font-size: 0.8rem; padding: 0.8rem; } }
    </style>
</head>

<body class="dashboard-body">

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light header">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <?= htmlspecialchars($_SESSION['restaurant_name'] ?? 'Restaurant Admin') ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashbaord</a>
                <a class="nav-link" href="../Common/logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container content py-4">
        <h3 class="text-center mb-4 text-white fw-bold">📋 Preparing Orders</h3>

        <!-- Back to Dashboard Button -->
        <div class="text-start back-btn">
            <a href="dashboard.php" class="btn btn-secondary">&larr; Back to Dashboard</a>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle text-center">
                <thead class="table-dark">
                    <tr>
                        <th>Table Number</th>
                        <th>Items</th>
                        <th>Total Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['table_number'] ?? 'N/A') ?></td>

                                <!-- Items column -->
                                <td style="text-align:left;">
                                    <?php
                                    $items = json_decode($row['items'], true);
                                    if ($items === null && json_last_error() !== JSON_ERROR_NONE) {
                                        $items = json_decode(stripslashes($row['items']), true);
                                    }

                                    if (is_array($items)) {
                                        foreach ($items as $item) {
                                            $item_id = $item['item_id'] ?? 0;
                                            $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 1;
                                            $notes = htmlspecialchars($item['notes'] ?? '');

                                            $item_name = "Unknown Item";
                                            if ($item_id > 0) {
                                                $stmt2 = $conn->prepare("SELECT item_name FROM menu_items WHERE id = ? AND restaurant_id = ?");
                                                $stmt2->bind_param('ii', $item_id, $_SESSION['restaurant_id']);
                                                $stmt2->execute();
                                                $res2 = $stmt2->get_result();
                                                if ($res2 && $res2->num_rows > 0) {
                                                    $row2 = $res2->fetch_assoc();
                                                    $item_name = htmlspecialchars($row2['item_name']);
                                                }
                                                $stmt2->close();
                                            }

                                            echo "<div>$item_name (x$quantity)";
                                            if (!empty($notes)) {
                                                echo " – <em>$notes</em>";
                                            }
                                            echo "</div>";
                                        }
                                    } else {
                                        echo htmlspecialchars($row['items']);
                                    }
                                    ?>
                                </td>

                                <td><?= number_format($row['total_amount'], 2) ?></td>
                                <td>
                                    <span class="badge bg-warning">
                                        <?= ucfirst(htmlspecialchars($row['status'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['created_at']) ?></td>
                                <td>
                                    <a href="bill.php?order_id=<?= $row['id'] ?>" class="btn btn-success btn-sm">
                                        💵 View Bill
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-muted">No preparing orders found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Footer -->
    <?php include('../Common/footer.php'); ?>

    <!-- JS Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>