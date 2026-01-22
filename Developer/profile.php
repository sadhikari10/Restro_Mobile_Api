<?php
session_start();

// Check if developer is logged in
if (empty($_SESSION['developer_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Get developer name from session
$developerName = $_SESSION['developer_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile</title>
</head>
<body>
    <?php 
        include('navbar.php');
    ?>
</body>
</html>
