<?php
require '../Common/connection.php';
require '../Common/nepali_date.php';

// Capture referring page (to go back after submission)
$referrer = $_SERVER['HTTP_REFERER'] ?? '../index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $referrer = $_POST['referrer'] ?? '../index.php';

    if ($message === '') {
        $error = "Message field cannot be empty!";
    } else {
        // --- Nepali DateTime (BS + NST) ---
        $ad_datetime = new DateTime('now', new DateTimeZone('Asia/Kathmandu'));
        $ad_date = $ad_datetime->format('Y-m-d');
        $ad_time = $ad_datetime->format('H:i:s');
        $bs_date = ad_to_bs($ad_date);
        $nepali_datetime = $bs_date . ' ' . $ad_time;

        // Default reviewed = 0 (false)
        $reviewed = 0;

        $stmt = $conn->prepare("
            INSERT INTO suggestions (name, email, message, submitted_at, reviewed)
            VALUES (?, ?, ?, ?, ?)
        ");

        if ($stmt === false) {
            die("Database error: " . $conn->error);
        }

        $stmt->bind_param('ssssi', $name, $email, $message, $nepali_datetime, $reviewed);

        if ($stmt->execute()) {
            $success = "Thank you for your suggestion!";
        } else {
            $error = "Database error: " . $stmt->error;
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Suggestion Box</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f8f9fa;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .container {
            max-width: 500px;
            margin: 40px auto;
            padding: 25px;
            background: white;
            box-shadow: 0 0 8px rgba(0,0,0,0.1);
            border-radius: 8px;
        }
        h2 { text-align: center; color: #333; }
        label { display: block; margin-top: 15px; font-weight: bold; }
        input, textarea {
            width: 100%; padding: 10px; margin-top: 5px;
            border: 1px solid #ccc; border-radius: 5px;
        }
        button {
            margin-top: 20px; padding: 10px 20px; background: #007bff;
            color: white; border: none; border-radius: 5px; cursor: pointer;
        }
        button:hover { background: #0056b3; }
        .message { text-align: center; margin-top: 15px; }
        footer {
            margin-top: auto;
            background: #222; color: #fff;
            text-align: center; padding: 12px 0;
        }
        .back-btn {
            background: #6c757d;
            margin-top: 10px;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Suggestion Box</h2>

    <?php if (!empty($success)): ?>
        <div class="message" style="color:green;"><?= htmlspecialchars($success) ?></div>
    <?php elseif (!empty($error)): ?>
        <div class="message" style="color:red;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="referrer" value="<?= htmlspecialchars($referrer) ?>">

        <label>Name:</label>
        <input type="text" name="name" placeholder="(Optional) Enter your name">

        <label>Email:</label>
        <input type="email" name="email" placeholder="(Optional) Enter your email">

        <label>Message:</label>
        <textarea name="message" rows="5" placeholder="Write your suggestion..." required></textarea>

        <button type="submit">Submit Suggestion</button>
        <button type="button" class="back-btn" onclick="window.location.href='<?= htmlspecialchars($referrer) ?>'">Go Back</button>
    </form>
</div>

<?php include '../Common/footer.php'; ?>

</body>
</html>
