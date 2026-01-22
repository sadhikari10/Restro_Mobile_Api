<?php
// trial_expired.php
$restaurant = isset($_GET['restaurant']) ? htmlspecialchars($_GET['restaurant']) : 'Your Restaurant';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trial Expired</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            font-family: Arial, sans-serif;
            text-align: center;
        }
        .expired-container {
            background: #fff;
            padding: 40px 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            max-width: 400px;
        }
        .expired-container img {
            max-width: 300px; /* doubled size */
            margin-bottom: 20px;
        }
        .expired-container h2 {
            color: #dc3545;
            margin-bottom: 15px;
        }
        .expired-container p {
            margin-bottom: 10px;
        }
        .scan {
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
        .contact {
            font-weight: bold;
            color: #333;
            margin-bottom: 20px;
        }
        .btn-back {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }
        .btn-back:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="expired-container">
        <img src="Qr.jpeg" alt="QR Code">
        <h2>Trial Period Expired</h2>
        <p>Your trial period for <strong><?= $restaurant ?></strong> has ended.</p>
        <p class="scan">Scan the QR to make payment and upgrade.</p>
        <p class="contact">If you have already paid, contact our officials at: <strong>+977-9840032900</strong></p>
        <a href="login.php" class="btn-back">Back to Login</a>
    </div>

    <?php include('footer.php'); ?>
</body>
</html>
