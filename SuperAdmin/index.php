<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

date_default_timezone_set('Asia/Kathmandu');

// === SECURITY: Must be logged in as superadmin ===
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../Common/login.php');
    exit;
}

$chain_id = $_SESSION['chain_id'];
$chain_name = '';
$restaurants = [];

// === HANDLE VIEW CLICK: Store restaurant_id in session & redirect ===
if (isset($_GET['set_view'])) {
    $restaurant_id = (int)$_GET['set_view'];

    // Store only the ID in session
    $_SESSION['current_restaurant_id'] = $restaurant_id;

    // Redirect to view_branch.php
    header('Location: view_branch.php');
    exit;
}

// Fetch Chain Name
$stmt = $conn->prepare("SELECT name FROM chains WHERE id = ?");
$stmt->bind_param("i", $chain_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $chain_name = $row['name'];
}
$stmt->close();

// Fetch All Restaurants (Branches) in the Chain
$stmt = $conn->prepare("
    SELECT id, name, address, phone_number, expiry_date, is_trial, created_at 
    FROM restaurants 
    WHERE chain_id = ? 
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $chain_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $restaurants[] = $row;
}
$stmt->close();
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SuperAdmin - <?= htmlspecialchars($chain_name) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
</head>
<body class="bg-light">

<!-- ========== NAVBAR ========== -->
<?php include 'navbar.php'; ?>

<div class="container mt-4">
  <div class="row">
    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-header bg-white">
          <h5 class="mb-0">
            Your Restaurant Branches
          </h5>
        </div>
        <div class="card-body">
          <?php if (empty($restaurants)): ?>
            <p class="text-muted">No restaurants added yet. <a href="add_branch.php">Add your first branch</a>.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Phone Number</th>
                    <th>Expiry (BS)</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($restaurants as $r): ?>
                    <?php
                      $today_bs = substr(nepali_date_time(), 0, 10);
                      $is_expired = $r['expiry_date'] < $today_bs;
                      $badge = $is_expired ? 'danger' : ($r['is_trial'] ? 'warning' : 'success');
                      $status = $is_expired ? 'Expired' : ($r['is_trial'] ? 'Trial' : 'Active');
                    ?>
                    <tr>
                      <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                      <td><?= htmlspecialchars($r['address'] ?? '-') ?></td>
                      <td><?= htmlspecialchars($r['phone_number']) ?></td>
                      <td><?= htmlspecialchars($r['expiry_date']) ?></td>
                      <td><span class="badge bg-<?= $badge ?>"><?= $status ?></span></td>
                      <td>
                        <a href="?set_view=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">
                          View
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <div class="mt-4">
            <a href="add_branch.php" class="btn btn-success">
              Add New Branch
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ========== FOOTER ========== -->
<?php include '../Common/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>