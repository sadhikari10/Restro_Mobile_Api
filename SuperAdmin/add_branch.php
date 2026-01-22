<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

date_default_timezone_set('Asia/Kathmandu');

// === SECURITY ===
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../login.php');
    exit;
}

$chain_id = $_SESSION['chain_id'] ?? null;
if (!$chain_id) {
    die("Chain ID missing.");
}

// Fetch chain name
$chain_name = '';
$stmt = $conn->prepare("SELECT name FROM chains WHERE id = ?");
$stmt->bind_param("i", $chain_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $chain_name = $row['name'];
}
$stmt->close();

// === FORM DATA PRESERVATION ===
$form = [
    'name' => '',
    'address' => '',
    'pan_number' => '',
    'restaurant_number' => '',
    'agree_terms' => 0
];

$message = '';
$show_success_modal = false;
$success_data = [];

$nepali_now = nepali_date_time();
$created_at = $nepali_now;
$agreed_terms_date = $nepali_now;
$expiry_date = date('Y-m-d', strtotime(substr($nepali_now, 0, 10) . ' +15 days'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['name']              = trim($_POST['name'] ?? '');
    $form['address']           = trim($_POST['address'] ?? '');
    $form['pan_number']        = trim($_POST['pan_number'] ?? '');
    $form['restaurant_number'] = preg_replace('/\D/', '', $_POST['restaurant_number'] ?? '');
    $form['agree_terms']       = isset($_POST['agree_terms']) ? 1 : 0;

    $name              = $form['name'];
    $address           = $form['address'];
    $pan_number        = $form['pan_number'];
    $restaurant_number = $form['restaurant_number'];
    $agree_terms       = $form['agree_terms'];

    // === VALIDATION ===
    if (empty($name) || empty($address) || empty($pan_number) || empty($restaurant_number)) {
        $message = 'All fields are required.';
    } elseif (!in_array(strlen($restaurant_number), [7, 10])) {
        $message = 'Restaurant Number must be 7 or 10 digits.';
    } elseif (strlen($restaurant_number) === 10 && $restaurant_number[0] === '0') {
        $message = '10-digit number cannot start with 0.';
    } elseif ($agree_terms != 1) {
        $message = 'You must agree to the Terms and Conditions.';
    } else {
        // === CHECK UNIQUENESS IN phone_number COLUMN ===
        $check = $conn->prepare("SELECT id FROM restaurants WHERE phone_number = ?");
        if ($check === false) {
            $message = "DB Error (check): " . $conn->error;
        } else {
            $check->bind_param('s', $restaurant_number);
            $check->execute();
            $result = $check->get_result();
            if ($result->num_rows > 0) {
                $message = 'This Phone Number is already in use.';
            }
            $check->close();
        }

        if (empty($message)) {
            // === INSERT INTO restaurants (phone_number column) ===
            $insert = $conn->prepare("
                INSERT INTO restaurants 
                (name, address, phone_number, pan_number, chain_id, is_trial, expiry_date, created_at, agreed_terms_date)
                VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
            ");

            if ($insert === false) {
                $message = "SQL Error: " . $conn->error;
            } else {
                $insert->bind_param(
                    'ssssisss',
                    $name,
                    $address,
                    $restaurant_number,
                    $pan_number,
                    $chain_id,
                    $expiry_date,
                    $created_at,
                    $agreed_terms_date
                );

                if ($insert->execute()) {
                    $insert->close();
                    $show_success_modal = true;
                    $success_data = ['name' => $name, 'number' => $restaurant_number];
                    // Reset form
                    $form = ['name' => '', 'address' => '', 'pan_number' => '', 'restaurant_number' => '', 'agree_terms' => 0];
                } else {
                    $message = "Insert failed: " . $insert->error;
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Add Restaurant - <?= htmlspecialchars($chain_name) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="../admin.css" rel="stylesheet">
  <style>
    html, body { height: 100%; margin: 0; }
    body {
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      background-color: #f9f9f9;
    }
    .main-content { flex: 1; padding-bottom: 2rem; }
    .card { border-radius: 1rem; }
    .form-control, .form-check-input { border-radius: 0.5rem; }
    .terms-link { color: #0d6efd; text-decoration: underline; }
    .terms-link:hover { color: #0b5ed7; }
    .form-text { font-size: 0.85rem; }

    /* Floating Success Modal */
    #successModal {
      position: fixed;
      top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      z-index: 1055;
      width: 90%;
      max-width: 400px;
      display: none;
    }
    .modal-backdrop {
      position: fixed;
      top: 0; left: 0; width: 100%; height: 100%;
      background: rgba(0,0,0,0.5);
      z-index: 1050;
      display: none;
    }
  </style>
</head>
<body>

<!-- ========== NAVBAR ========== -->
<?php include 'navbar.php'; ?>

<!-- ========== MAIN CONTENT ========== -->
<div class="main-content">
  <div class="container mt-4">
    <div class="row justify-content-center">
      <div class="col-12 col-md-8 col-lg-6">
        <div class="card shadow">
          <div class="card-header bg-white text-center">
            <h5 class="mb-0">
              <i class="bi bi-plus-circle text-success"></i> Add New Restaurant
            </h5>
          </div>
          <div class="card-body p-4">

            <!-- Error Alert -->
            <?php if ($message): ?>
              <div class="alert alert-danger alert-dismissible fade show">
                <strong>Error:</strong> <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
              </div>
            <?php endif; ?>

            <form method="post" action="" novalidate>
              <div class="mb-3">
                <label class="form-label">Restaurant Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" required 
                       value="<?= htmlspecialchars($form['name']) ?>" 
                       placeholder="e.g., Himalayan Cafe">
              </div>

              <div class="mb-3">
                <label class="form-label">Address <span class="text-danger">*</span></label>
                <input type="text" name="address" class="form-control" required 
                       value="<?= htmlspecialchars($form['address']) ?>" 
                       placeholder="Full address">
              </div>

              <div class="mb-3">
                <label class="form-label">PAN Number <span class="text-danger">*</span></label>
                <input type="text" name="pan_number" class="form-control" required 
                       value="<?= htmlspecialchars($form['pan_number']) ?>" 
                       placeholder="e.g., 123456789">
              </div>

              <div class="mb-3">
                <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                <input type="text" name="restaurant_number" class="form-control" required 
                       pattern="[0-9]{7}|[0-9]{10}" inputmode="numeric"
                       value="<?= htmlspecialchars($form['restaurant_number']) ?>"
>
              </div>

              <div class="mb-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="agree_terms" id="agree_terms" 
                         <?= $form['agree_terms'] ? 'checked' : '' ?> required>
                  <label class="form-check-label" for="agree_terms">
                    I agree to the 
                    <a href="../Common/terms.php" target="_blank" class="terms-link">Terms of Service</a> and 
                    <a href="../Common/privacy_policy.php" target="_blank" class="terms-link">Privacy Policy</a>
                  </label>
                </div>
              </div>

              <button type="submit" class="btn btn-primary w-100 btn-lg">
                <i class="bi bi-check-circle"></i> Create Restaurant
              </button>
            </form>

            <div class="text-center mt-3">
              <a href="index.php" class="text-muted small">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ========== SUCCESS MODAL ========== -->
<div class="modal-backdrop" id="modalBackdrop"></div>
<div class="card shadow-lg" id="successModal">
  <div class="card-body text-center p-4">
    <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
    <h4 class="mt-3">Restaurant Added!</h4>
    <p class="text-muted">
      <strong><?= htmlspecialchars($success_data['name'] ?? '') ?></strong>
    <button type="button" class="btn btn-success" id="closeModal">
      OK
    </button>
  </div>
</div>

<!-- ========== FOOTER ========== -->
<?php include '../Common/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Auto-remove non-digits
  const input = document.querySelector('input[name="restaurant_number"]');
  input?.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '');
  });

  // Show success modal
  <?php if ($show_success_modal): ?>
    document.getElementById('successModal').style.display = 'block';
    document.getElementById('modalBackdrop').style.display = 'block';
  <?php endif; ?>

  // Close modal & redirect
  document.getElementById('closeModal')?.addEventListener('click', function() {
    document.getElementById('successModal').style.display = 'none';
    document.getElementById('modalBackdrop').style.display = 'none';
    window.location.href = 'index.php';
  });

  document.getElementById('modalBackdrop')?.addEventListener('click', function() {
    document.getElementById('closeModal').click();
  });
</script>
</body>
</html>