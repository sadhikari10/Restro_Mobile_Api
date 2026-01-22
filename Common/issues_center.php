<?php
require '../Common/connection.php';
require '../Common/nepali_date.php';

// Capture the referring page (the page user came from)
$referrer = $_SERVER['HTTP_REFERER'] ?? '../index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $file_name = null;
    $referrer = $_POST['referrer'] ?? '../index.php'; // Keep same referrer even after post

    if ($name === '' || $email === '' || $message === '') {
        $error = "All fields are required!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } 
    else {
        // File upload
        if (!empty($_FILES['file']['name'])) {
            $upload_dir = __DIR__ . '/../uploads/issues/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $file_ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            $file_name = uniqid() . '.' . $file_ext;
            $target_path = $upload_dir . $file_name;
            move_uploaded_file($_FILES['file']['tmp_name'], $target_path);
        }

        // --- Proper Nepali DateTime (BS + NST) ---
        $ad_datetime = new DateTime('now', new DateTimeZone('Asia/Kathmandu'));
        $ad_date = $ad_datetime->format('Y-m-d');
        $ad_time = $ad_datetime->format('H:i:s');
        $bs_date = ad_to_bs($ad_date); // Convert AD date to BS
        $nepali_datetime = $bs_date . ' ' . $ad_time; // Combine BS date + NST time

        // Prepare insert query
        $stmt = $conn->prepare("
            INSERT INTO issues (name, email, message, file, submitted_at, reviewed)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($stmt === false) {
            die("Database error: " . $conn->error);
        }

        $reviewed = 0; // default false
        $stmt->bind_param('sssssi', $name, $email, $message, $file_name, $nepali_datetime, $reviewed);

        if ($stmt->execute()) {
            $success = "Your issue has been submitted successfully!";
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
    <title>Issue Center</title>
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
    <h2>Issue Center</h2>
    <?php if (!empty($success)): ?>
        <div class="message" style="color:green;"><?= htmlspecialchars($success) ?></div>
    <?php elseif (!empty($error)): ?>
        <div class="message" style="color:red;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="referrer" value="<?= htmlspecialchars($referrer) ?>">

        <label>Name:</label>
        <input type="text" name="name" required>

        <label>Email:</label>
        <input type="email" name="email" required>

        <label>Message:</label>
        <textarea name="message" rows="5" required></textarea>

        <label>Attach file (image/video):</label>
        <input type="file" name="file" accept="image/*,video/*" required>

        <button type="submit">Submit Issue</button>
        <button type="button" class="back-btn" onclick="window.location.href='<?= htmlspecialchars($referrer) ?>'">Go Back</button>
    </form>
</div>

<?php include '../Common/footer.php'; ?>

</body>
</html>
