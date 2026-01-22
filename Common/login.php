<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

date_default_timezone_set('Asia/Kathmandu');

// If already logged in, redirect based on role
if (!empty($_SESSION['logged_in'])) {
    $role = $_SESSION['role'];
    if ($role === 'superadmin') {
        header('Location: ../SuperAdmin/index.php');
    } elseif ($role === 'admin') {
        header('Location: ../Admin/dashboard.php');
    } elseif ($role === 'staff') {
        header('Location: ../Staff/dashboard.php');
    }
    exit;
}

$message = '';
$success = '';
$email_input = '';
$role_input = '';

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = 'Account created successfully! Please login to continue.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email_input = trim($_POST['email'] ?? '');
    $password    = $_POST['password'] ?? '';
    $role_input  = $_POST['role'] ?? '';

    // Handle normal login
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        if (empty($email_input) || empty($password) || empty($role_input)) {
            $message = 'Please enter email, role, and password.';
        } else {
            // Map UI role → DB role
            $db_role = match ($role_input) {
                'owner' => 'superadmin',
                'admin' => 'admin',
                'staff' => 'staff',
                default => null
            };

            if (!$db_role) {
                $message = 'Invalid role selected.';
            } else {
                // Prepare query based on role
                if ($db_role === 'superadmin') {
                    $stmt = $conn->prepare("
                        SELECT u.id, u.username, u.password, u.restaurant_id, u.chain_id, u.role, u.status,
                               NULL AS expiry_date, 0 AS is_trial, 'Super Admin Panel' AS restaurant_name
                        FROM users u
                        WHERE u.email = ? AND u.role = ?
                    ");
                } else {
                    $stmt = $conn->prepare("
                        SELECT u.id, u.username, u.password, u.restaurant_id, u.chain_id, u.role, u.status,
                               r.expiry_date, r.is_trial, r.name AS restaurant_name
                        FROM users u
                        INNER JOIN restaurants r ON u.restaurant_id = r.id
                        WHERE u.email = ? AND u.role = ?
                    ");
                }

                $stmt->bind_param('ss', $email_input, $db_role);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    $db_hash = $user['password'] ?? '';

                    if (password_verify($password, $db_hash)) {
                        if ($user['status'] !== 'active') {
                            $message = 'Account is disabled. Contact admin.';
                        } else {
                            if ($db_role !== 'superadmin') {
                                $bs_now_full = nepali_date_time();
                                $bs_now_date = substr($bs_now_full, 0, 10);

                                if ($user['expiry_date'] < $bs_now_date) {
                                    if ($user['is_trial'] == 1) {
                                        header('Location: trial_expired.php?restaurant=' . urlencode($user['restaurant_name']));
                                        exit;
                                    } else {
                                        header('Location: software_expired.php?restaurant=' . urlencode($user['restaurant_name']));
                                        exit;
                                    }
                                }
                            }

                            $_SESSION['logged_in']       = true;
                            $_SESSION['user_id']         = $user['id'];
                            $_SESSION['username']        = $user['username'];
                            $_SESSION['email']           = $email_input;
                            $_SESSION['role']            = $db_role;
                            $_SESSION['restaurant_id']   = $user['restaurant_id'] ?? null;
                            $_SESSION['chain_id']        = $user['chain_id'] ?? null;
                            $_SESSION['restaurant_name'] = $user['restaurant_name'] ?? 'Super Admin Panel';
                            $_SESSION['login_time_bs']   = $bs_now_full ?? nepali_date_time();

                            session_regenerate_id(true);

                            if ($db_role === 'superadmin') {
                                header('Location: ../SuperAdmin/index.php');
                            } elseif ($db_role === 'admin') {
                                header('Location: ../Admin/dashboard.php');
                            } elseif ($db_role === 'staff') {
                                header('Location: ../Staff/dashboard.php');
                            }
                            exit;
                        }
                    } else {
                        $message = 'Incorrect email, role, or password.';
                    }
                } else {
                    $message = 'Incorrect email, role, or password.';
                }
                $stmt->close();
            }
        }
    }

    // Handle Forgot Password Request
    if (isset($_POST['request_reset'])) {
        $forgot_email = trim($_POST['forgot_email'] ?? '');

        if (empty($forgot_email) || !filter_var($forgot_email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
        } else {
            $stmt = $conn->prepare("SELECT id, reset_attempts, last_reset_request FROM users WHERE email = ?");
            $stmt->bind_param('s', $forgot_email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();

                $now = new DateTime();
                $last_request = $user['last_reset_request'] ? new DateTime($user['last_reset_request']) : null;
                $interval = $last_request ? $now->diff($last_request) : null;

                if ($user['reset_attempts'] >= 3 && $interval && $interval->h < 1) {
                    $message = 'Too many reset attempts. Please try again in 1 hour.';
                } else {
                    $token = bin2hex(random_bytes(32));
                    $expiry = date('Y-m-d H:i:s', strtotime('+30 minutes'));

                    $new_attempts = ($interval && $interval->h >= 1) ? 1 : $user['reset_attempts'] + 1;

                    $update_stmt = $conn->prepare("
                        UPDATE users 
                        SET reset_token = ?, reset_token_expiry = ?, reset_attempts = ?, last_reset_request = NOW()
                        WHERE email = ?
                    ");
                    $update_stmt->bind_param('ssis', $token, $expiry, $new_attempts, $forgot_email);

                    if ($update_stmt->execute()) {
                        // Lazy load email config only when needed
                        $emailConfigPath = '../Common/email_config.php';
                        if (file_exists($emailConfigPath)) {
                            require_once $emailConfigPath;
                            if (function_exists('sendResetEmail') && sendResetEmail($forgot_email, $token)) {
                                $success = 'Password reset link sent! Check your email (including spam).';
                            } else {
                                $message = 'Failed to send email. Please try again later.';
                            }
                        } else {
                            $message = 'Email system not configured.';
                        }
                    } else {
                        $message = 'Something went wrong. Please try again.';
                    }
                    $update_stmt->close();
                }
            } else {
                $success = 'If that email exists, a reset link has been sent.';
            }
            $stmt->close();
        }
    }
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Restaurant Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
    #floatingAlert {
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      z-index: 1055;
      width: 90%;
      max-width: 400px;
      display: none;
    }
    .alert-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.5);
      z-index: 1050;
      display: none;
    }
  </style>
</head>
<body class="login-body">

  <div class="alert-backdrop" id="alertBackdrop"></div>

  <div id="floatingAlert" class="alert shadow-lg" role="alert">
    <button type="button" class="btn-close float-end" id="closeAlert"></button>
    <div id="alertContent"></div>
  </div>

  <div class="container">
    <div class="row justify-content-center">
      <div class="col-12 col-sm-8 col-md-6 col-lg-4">
        <div class="card shadow-sm login-card">
          <div class="card-body p-4">
            <div class="text-center mb-4">
              <h4 class="card-title brand">Restaurant Login</h4>
              <p class="text-muted small">Welcome back! Sign in to manage your restaurant.</p>
            </div>

            <form method="post" action="">
              <input type="hidden" name="action" value="login">

              <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input id="email" name="email" type="email" class="form-control" required 
                       value="<?= htmlspecialchars($email_input) ?>" autocomplete="email" autofocus>
              </div>

              <div class="mb-3 position-relative">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                  <input id="password" name="password" type="password" class="form-control" required autocomplete="current-password">
                  <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                    <i class="bi bi-eye" id="passwordIcon"></i>
                  </button>
                </div>
              </div>

              <div class="mb-3">
                <label for="role" class="form-label">Role</label>
                <select id="role" name="role" class="form-select" required>
                  <option value="">Select Role</option>
                  <option value="owner" <?= $role_input === 'owner' ? 'selected' : '' ?>>Owner</option>
                  <option value="admin" <?= $role_input === 'admin' ? 'selected' : '' ?>>Admin</option>
                  <option value="staff" <?= $role_input === 'staff' ? 'selected' : '' ?>>Staff</option>
                </select>
              </div>

              <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">Login</button>
              </div>

              <div class="text-center mt-3">
                <a href="signup.php" class="text-muted small">Don't have an account? Sign Up</a>
              </div>
              <div class="text-center mt-2">
                  <a href="#" class="text-primary small" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">
                      Forgot Password?
                  </a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Forgot Password Modal -->
  <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="forgotPasswordModalLabel">Reset Your Password</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="forgotForm" method="post" action="">
            <div class="mb-3">
              <label for="forgot_email" class="form-label">Email Address</label>
              <input type="email" class="form-control" id="forgot_email" name="forgot_email" required autofocus>
            </div>
            <input type="hidden" name="request_reset" value="1">
            <div class="d-grid">
              <button type="submit" class="btn btn-primary">Send Reset Link</button>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <small class="text-muted">We'll send a reset link if the email exists.</small>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.getElementById('togglePassword')?.addEventListener('click', function () {
      const input = document.getElementById('password');
      const icon = document.getElementById('passwordIcon');
      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
      } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
      }
    });

    function showAlert(msg, type) {
      const alert = document.getElementById('floatingAlert');
      const content = document.getElementById('alertContent');
      const backdrop = document.getElementById('alertBackdrop');
      content.innerHTML = msg;
      alert.className = 'alert alert-' + type + ' shadow-lg';
      alert.style.display = 'block';
      backdrop.style.display = 'block';
    }

    document.getElementById('closeAlert').onclick = () => {
      document.getElementById('floatingAlert').style.display = 'none';
      document.getElementById('alertBackdrop').style.display = 'none';
    };
    document.getElementById('alertBackdrop').onclick = () => {
      document.getElementById('floatingAlert').style.display = 'none';
      document.getElementById('alertBackdrop').style.display = 'none';
    };

    <?php if ($message): ?>
      showAlert(`<?= addslashes($message) ?>`, 'danger');
    <?php endif; ?>

    <?php if ($success): ?>
      showAlert(`<?= addslashes($success) ?>`, 'success');
    <?php endif; ?>
  </script>

  <?php include('footer.php'); ?>
</body>
</html>