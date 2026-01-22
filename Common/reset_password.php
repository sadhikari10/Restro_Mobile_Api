<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

// If somehow logged in, redirect to dashboard
if (!empty($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

$message = '';
$success = '';
$token = $_GET['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $token = $_POST['token'] ?? '';

    if (empty($new_password) || empty($confirm_password)) {
        $message = 'Please fill in both password fields.';
    } elseif ($new_password !== $confirm_password) {
        $message = 'Passwords do not match.';
    } elseif (strlen($new_password) < 6) {
        $message = 'Password must be at least 6 characters long.';
    } else {
        $stmt = $conn->prepare("SELECT id, email, reset_token_expiry FROM users WHERE reset_token = ?");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($user['reset_token_expiry'] && strtotime($user['reset_token_expiry']) > time()) {
                // Valid token — update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                $update_stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
                $update_stmt->bind_param('si', $hashed_password, $user['id']);

                if ($update_stmt->execute()) {
                    $success = 'Your password has been successfully reset! You can now log in.';
                    // Optional: log the user in automatically?
                } else {
                    $message = 'Something went wrong. Please try again.';
                }
                $update_stmt->close();
            } else {
                $message = 'This reset link has expired. Please request a new one.';
            }
        } else {
            $message = 'Invalid or used reset link.';
        }
        $stmt->close();
    }
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset Password</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    body { background: #f8f9fa; }
    .card { max-width: 420px; margin: 4rem auto; }
    .password-toggle { cursor: pointer; }
  </style>
</head>
<body>
  <div class="container">
    <div class="card shadow">
      <div class="card-body p-5">
        <div class="text-center mb-4">
          <h3>Reset Your Password</h3>
          <p class="text-muted">Enter your new password below</p>
        </div>

        <?php if ($message): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="alert alert-success">
            <?= htmlspecialchars($success) ?>
            <hr>
            <a href="login.php" class="btn btn-primary">Go to Login</a>
          </div>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            
            <div class="mb-3 position-relative">
              <label class="form-label">New Password</label>
              <div class="input-group">
                <input type="password" name="new_password" class="form-control" required minlength="6">
                <span class="input-group-text password-toggle">
                  <i class="bi bi-eye" id="toggleNew"></i>
                </span>
              </div>
            </div>

            <div class="mb-4 position-relative">
              <label class="form-label">Confirm Password</label>
              <div class="input-group">
                <input type="password" name="confirm_password" class="form-control" required>
                <span class="input-group-text password-toggle">
                  <i class="bi bi-eye" id="toggleConfirm"></i>
                </span>
              </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">Reset Password</button>
          </form>

          <div class="text-center mt-4">
            <a href="login.php" class="text-muted small">← Back to Login</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
    document.querySelectorAll('.password-toggle').forEach(btn => {
      btn.addEventListener('click', () => {
        const input = btn.previousElementSibling;
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
          input.type = 'text';
          icon.classList.replace('bi-eye', 'bi-eye-slash');
        } else {
          input.type = 'password';
          icon.classList.replace('bi-eye-slash', 'bi-eye');
        }
      });
    });
  </script>
</body>
</html>