<?php
session_start();
date_default_timezone_set('Asia/Kathmandu');
require '../Common/connection.php';
require 'nepali_date.php';

if (!empty($_SESSION['logged_in'])) {
    header('Location: ../SuperAdmin/index.php');
    exit;
}

$username = $email = $phone = '';
$agree_terms = 0;
$message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username         = trim($_POST['username'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $agree_terms      = isset($_POST['agree_terms']) ? 1 : 0;

    $errors = [];

    if (empty($username)) $errors[] = 'Username is required.';
    if (empty($email)) {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (empty($phone)) {
        $errors[] = 'Phone number is required.';
    } elseif (!preg_match('/^\d{7}$|^\d{10}$/', $phone)) {
        $errors[] = 'Phone must be 7 or 10 digits.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)/', $password)) {
        $errors[] = 'Password must contain letters and numbers.';
    }
    if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';
    if (!$agree_terms) $errors[] = 'You must agree to Terms and Privacy Policy.';

    // === DUPLICATE CHECKS ===
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
        $stmt->bind_param("ss", $email, $phone);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            if ($check_email->get_result()->num_rows > 0) $errors[] = 'This email is already registered.';
            $check_email->close();

            $check_phone = $conn->prepare("SELECT id FROM users WHERE phone = ?");
            $check_phone->bind_param("s", $phone);
            $check_phone->execute();
            if ($check_phone->get_result()->num_rows > 0) $errors[] = 'This phone number is already registered.';
            $check_phone->close();
        }
        $stmt->close();
    }

    // === INSERT CHAIN + USER (restaurant_id = NULL) ===
    if (empty($errors)) {
        $conn->autocommit(false);
        $bs_now = nepali_date_time();

        try {
            // 1. Create Chain
            $chain_name = "Restaurant Software"; // Fixed
            $stmt = $conn->prepare("INSERT INTO chains (name, created_at) VALUES (?, ?)");
            $stmt->bind_param("ss", $chain_name, $bs_now);
            $stmt->execute();
            $chain_id = $conn->insert_id;
            $stmt->close();

            // 2. Create Superadmin User
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'superadmin';  // MUST MATCH login mapping
            $status = 'active';
            $restaurant_id = null; // NULL allowed

            $stmt = $conn->prepare("
                INSERT INTO users 
                (restaurant_id, chain_id, username, password, email, phone, role, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "iisssssss",
                $restaurant_id,
                $chain_id,
                $username,
                $hashed_password,
                $email,
                $phone,
                $role,
                $status,
                $bs_now
            );
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $success_message = 'Account created! Please log in to add your first restaurant.';

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Signup failed: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $message = implode('<br>', $errors);
    }
}
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign Up - Restaurant Owner</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="admin.css" rel="stylesheet">
<style>
#floatingAlert, #alertBackdrop { display: none; position: fixed; z-index: 1050; }
#floatingAlert { top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: 500px; }
#alertBackdrop { top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
</style>
</head>
<body class="login-body">

<div id="alertBackdrop"></div>
<div id="floatingAlert" class="alert shadow-lg">
  <div id="alertContent"></div>
  <button id="closeAlert" class="btn-close position-absolute top-0 end-0 m-2"></button>
</div>

<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
      <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
          <h3 class="text-center mb-4">Create Owner Account</h3>
          <p class="text-center text-muted small">Start managing your restaurant chain</p>

          <form method="post" action="">

            <div class="mb-3">
              <label class="form-label">Username</label>
              <input name="username" type="text" class="form-control" required 
                     value="<?= htmlspecialchars($username) ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">Email</label>
              <input name="email" type="email" class="form-control" required 
                     value="<?= htmlspecialchars($email) ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">Phone <small class="text-muted">(7 or 10 digits)</small></label>
              <input name="phone" type="tel" class="form-control" required 
                     value="<?= htmlspecialchars($phone) ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">Password</label>
              <div class="input-group">
                <input name="password" type="password" class="form-control" required>
                <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                  <i class="bi bi-eye" id="passwordIcon"></i>
                </button>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Confirm Password</label>
              <div class="input-group">
                <input name="confirm_password" type="password" class="form-control" required>
                <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                  <i class="bi bi-eye" id="confirmPasswordIcon"></i>
                </button>
              </div>
            </div>

            <div class="mb-3 form-check">
              <input type="checkbox" class="form-check-input" id="agree_terms" name="agree_terms" 
                     <?= $agree_terms ? 'checked' : '' ?>>
              <label class="form-check-label" for="agree_terms">
                I agree to the <a href="terms.php" target="_blank">Terms</a> & 
                <a href="privacy_policy.php" target="_blank">Privacy Policy</a>.
              </label>
            </div>

            <div class="d-grid">
              <button type="submit" class="btn btn-primary btn-lg">Create Account</button>
            </div>

            <div class="text-center mt-3">
              <a href="login.php" class="text-muted small">Already have an account? Login</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Password toggle
['togglePassword', 'toggleConfirmPassword'].forEach(id => {
  document.getElementById(id)?.addEventListener('click', function() {
    const input = this.previousElementSibling;
    const icon = this.querySelector('i');
    if (input.type === 'password') {
      input.type = 'text';
      icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
      input.type = 'password';
      icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
  });
});

function showAlert(msg, type, redirect = false) {
  const alert = document.getElementById('floatingAlert');
  const content = document.getElementById('alertContent');
  const backdrop = document.getElementById('alertBackdrop');
  const closeBtn = document.getElementById('closeAlert');

  content.innerHTML = msg;
  alert.className = 'alert alert-' + type + ' shadow-lg';
  alert.style.display = 'block';
  backdrop.style.display = 'block';

  if (redirect) {
    closeBtn.onclick = () => location.href = 'login.php';
    backdrop.onclick = () => location.href = 'login.php';
  } else {
    closeBtn.onclick = () => {
      alert.style.display = 'none';
      backdrop.style.display = 'none';
    };
    backdrop.onclick = () => {
      alert.style.display = 'none';
      backdrop.style.display = 'none';
    };
  }
}

<?php if ($message): ?>
showAlert(`<?= addslashes($message) ?>`, 'danger');
<?php endif; ?>

<?php if ($success_message): ?>
showAlert(`<?= addslashes($success_message) ?>`, 'success', true);
<?php endif; ?>
</script>

<?php include('footer.php'); ?>
</body>
</html>