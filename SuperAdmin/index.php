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
$has_expired = false;

// Helper: Compare two BS dates correctly (YYYY-MM-DD)
function bs_date_lt(string $date1, string $date2): bool {
    if (empty($date1) || empty($date2)) return false;
    [$y1, $m1, $d1] = array_map('intval', explode('-', $date1));
    [$y2, $m2, $d2] = array_map('intval', explode('-', $date2));
    if ($y1 !== $y2) return $y1 < $y2;
    if ($m1 !== $m2) return $m1 < $m2;
    return $d1 < $d2;
}

// === HANDLE VIEW CLICK: Store restaurant_id in session & redirect ===
if (isset($_GET['set_view'])) {
    $restaurant_id = (int)$_GET['set_view'];
    $_SESSION['current_restaurant_id'] = $restaurant_id;
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

// Check for any expired branches & mark each one
$today_bs = substr(nepali_date_time(), 0, 10); // e.g. "2082-05-15"

foreach ($restaurants as &$r) {
    $r['is_expired'] = !empty($r['expiry_date']) && bs_date_lt($r['expiry_date'], $today_bs);
    if ($r['is_expired']) {
        $has_expired = true;
    }
}
unset($r);
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
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            Your Restaurant Branches
          </h5>
          <a href="add_branch.php" class="btn btn-sm btn-success">
            <i class="bi bi-plus-lg"></i> Add New Branch
          </a>
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
                      $is_expired = $r['is_expired'];
                      $badge = $is_expired ? 'danger' : ($r['is_trial'] ? 'warning' : 'success');
                      $status = $is_expired ? 'Expired' : ($r['is_trial'] ? 'Trial' : 'Active');
                    ?>
                    <tr class="<?= $is_expired ? 'table-danger' : '' ?>">
                      <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                      <td><?= htmlspecialchars($r['address'] ?? '-') ?></td>
                      <td><?= htmlspecialchars($r['phone_number'] ?? '-') ?></td>
                      <td><?= htmlspecialchars($r['expiry_date'] ?: '-') ?></td>
                      <td><span class="badge bg-<?= $badge ?>"><?= $status ?></span></td>
                      <td>
                        <?php if (!$is_expired): ?>
                          <a href="?set_view=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">
                            View
                          </a>
                        <?php else: ?>
                          <span class="badge bg-secondary">View unavailable</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

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