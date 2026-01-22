<?php
// Admin/create_customer.php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['restaurant_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$restaurant_id = (int)$_SESSION['restaurant_id'];

// Get form data
$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');

if (strlen($name) < 2) {
    echo json_encode(['success' => false, 'error' => 'Name is required and must be at least 2 characters']);
    exit;
}

// Get current logged-in user ID
$user_id = 0;
if (isset($_SESSION['username'])) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $_SESSION['username']);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $user_id = (int)($res['id'] ?? 0);
    $stmt->close();
}

// Generate current Nepali date/time
$created_bs_datetime = nepali_date_time();  // e.g. 2082-09-15 14:30:22

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("
        INSERT INTO customers 
        (restaurant_id, name, phone, address, total_consumed, total_paid, remaining_due, 
         created_by, updated_by, created_bs_datetime, updated_bs_datetime)
        VALUES (?, ?, ?, ?, 0.00, 0.00, 0.00, ?, ?, ?, NULL)
    ");

    // Bind parameters: i s s s i i s
    $stmt->bind_param('isssiis', $restaurant_id, $name, $phone, $address, $user_id, $user_id, $created_bs_datetime);
    $stmt->execute();
    $new_customer_id = $conn->insert_id;
    $stmt->close();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'customer_id' => $new_customer_id,
        'name' => $name,
        'phone' => $phone ?: 'No phone',
        'due' => '0.00'
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>