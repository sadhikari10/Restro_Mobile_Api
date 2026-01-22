<?php
// SuperAdmin/orders.php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin' || empty($_SESSION['current_restaurant_id'])) {
    header('Location: ../login.php');
    exit;
}

$restaurant_id = $_SESSION['current_restaurant_id'];

// Fetch restaurant name
$stmt = $conn->prepare("SELECT name FROM restaurants WHERE id = ?");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$res = $stmt->get_result();
$restaurant = $res->fetch_assoc();
$stmt->close();
$restaurant_name = $restaurant['name'] ?? 'Branch';

// Fetch preparing orders
$query = "SELECT * FROM orders WHERE restaurant_id = ? AND status = 'preparing' ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Preparing Orders - <?= htmlspecialchars($restaurant_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../admin.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .table { background: white; }
        .back-btn { margin: 1rem 0; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container py-5">
    <div class="card shadow-lg">
        <div class="card-header bg-primary text-white text-center">
            <h4 class="mb-0">Preparing Orders - <?= htmlspecialchars($restaurant_name) ?></h4>
        </div>
        <div class="card-body">
            <div class="text-start back-btn">
                <a href="view_branch.php" class="btn btn-light border">&larr; Back </a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Table</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($row['table_number']) ?></strong></td>
                                    <td style="text-align:left; max-width: 300px;">
                                        <?php
                                        $items = json_decode($row['items'], true) ?? [];
                                        foreach ($items as $item) {
                                            $name = "Item";
                                            if (!empty($item['item_id'])) {
                                                $stmt2 = $conn->prepare("SELECT item_name FROM menu_items WHERE id = ?");
                                                $stmt2->bind_param("i", $item['item_id']);
                                                $stmt2->execute();
                                                $res2 = $stmt2->get_result();
                                                if ($res2->num_rows > 0) {
                                                    $name = $res2->fetch_assoc()['item_name'];
                                                }
                                                $stmt2->close();
                                            }
                                            $qty = $item['quantity'] ?? 1;
                                            $notes = !empty($item['notes']) ? " – <em>" . htmlspecialchars($item['notes']) . "</em>" : "";
                                            echo "<div><strong>$name</strong> × $qty$notes</div>";
                                        }
                                        ?>
                                    </td>
                                    <td><strong>Rs <?= number_format($row['total_amount'], 2) ?></strong></td>
                                    <td><?= htmlspecialchars($row['created_at']) ?></td>
                                    <td>
                                        <a href="bill.php?order_id=<?= $row['id'] ?>" class="btn btn-success btn-sm">
                                            View Bill
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <h5>No preparing orders</h5>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../Common/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>