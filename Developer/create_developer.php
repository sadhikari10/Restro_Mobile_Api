<?php
// create_developer_account.php
// Run this file ONCE in your browser or via CLI, then DELETE it for security!

require '../Common/connection.php'; // Adjust path if needed

$username = 'Sushant Adhikari';
$email    = 'sushantadhikari70@gmail.com';
$password = 'Sushant@123'; // Plain text password

// Hash the password securely
$password_hash = password_hash($password, PASSWORD_DEFAULT);

echo "<pre>";
echo "Creating developer account...\n\n";
echo "Username: $username\n";
echo "Email:    $email\n";
echo "Password: $password (will be hashed)\n\n";

// Check if email already exists
$stmt = $conn->prepare("SELECT id FROM developer WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    echo "Error: A developer with email '$email' already exists!\n";
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();

// Insert new developer
$stmt = $conn->prepare("INSERT INTO developer (username, email, password_hash) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $username, $email, $password_hash);

if ($stmt->execute()) {
    echo "Success: Developer account created successfully!\n";
    echo "You can now login with:\n";
    echo "   Email:    $email\n";
    echo "   Password: $password\n";
} else {
    echo "Error: Could not create account. " . $stmt->error . "\n";
}

$stmt->close();
$conn->close();
echo "</pre>";
?>