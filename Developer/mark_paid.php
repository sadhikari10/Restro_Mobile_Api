<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['developer_logged_in'])) {
    echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
    exit;
}

// Load Composer + Nepali helpers
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    echo json_encode(['success' => false, 'msg' => 'Composer autoload missing']);
    exit;
}
require_once $autoload;

$nepali_file = '../Common/nepali_date.php';
if (!file_exists($nepali_file)) {
    echo json_encode(['success' => false, 'msg' => 'nepali_date.php missing']);
    exit;
}
require_once $nepali_file;

require '../Common/connection.php';

if (empty($_POST['restaurant_id'])) {
    echo json_encode(['success' => false, 'msg' => 'Missing ID']);
    exit;
}
$restaurant_id = (int)$_POST['restaurant_id'];

// 1. Get current expiry (BS)
$stmt = $conn->prepare("SELECT expiry_date FROM restaurants WHERE id = ?");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$res = $stmt->get_result();
if (!$row = $res->fetch_assoc()) {
    $stmt->close();
    echo json_encode(['success' => false, 'msg' => 'Restaurant not found']);
    exit;
}
$old_expiry_bs = $row['expiry_date'];
$stmt->close();

// 2. Add 1 Nepali year
list($y, $m, $d) = explode('-', $old_expiry_bs);
$new_year = (int)$y + 1;
$new_expiry_bs = sprintf("%04d-%02d-%02d", $new_year, $m, $d);

// 3. Update restaurant
$upd = $conn->prepare("UPDATE restaurants SET expiry_date = ?, is_trial = 0 WHERE id = ?");
$upd->bind_param("si", $new_expiry_bs, $restaurant_id);
$upd->execute();
$upd->close();

// 4. Log to payment_history
$created_at_bs = nepali_date_time(); // 2081-07-20 14:30:25

$ins = $conn->prepare("
    INSERT INTO payment_history 
    (restaurant_id, old_expiry_date, new_expiry_date, created_at) 
    VALUES (?, ?, ?, ?)
");
$ins->bind_param("isss", $restaurant_id, $old_expiry_bs, $new_expiry_bs, $created_at_bs);
$ins->execute();
$ins->close();

$conn->close();
echo json_encode(['success' => true]);