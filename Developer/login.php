<?php
session_start();
include '../Common/connection.php'; // Adjust path if needed

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    // Prepare statement to get developer by email
    $stmt = $conn->prepare("SELECT id, username, password_hash FROM developer WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $developer = $result->fetch_assoc();
        // Verify password
        if (password_verify($password, $developer['password_hash'])) {
            // Login success, store session
            $_SESSION['developer_logged_in'] = true;
            $_SESSION['developer_id'] = $developer['id'];
            $_SESSION['developer_name'] = $developer['username'];
            header('Location: dashboard.php'); // redirect to your developer dashboard
            exit;
        } else {
            $message = "Incorrect password.";
        }
    } else {
        $message = "Email not found.";
    }

    $stmt->close();
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Developer Login</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; }
        .login-container { width: 300px; margin: 100px auto; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 0 10px #aaa; }
        input[type="email"], input[type="password"] { width: 100%; padding: 8px; margin: 8px 0; }
        button { width: 100%; padding: 10px; background: #28a745; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        .error { color: red; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Developer Login</h2>
        <?php if ($message) echo '<div class="error">' . $message . '</div>'; ?>
        <form method="POST" action="">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>
