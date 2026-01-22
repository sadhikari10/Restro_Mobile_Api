<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['developer_logged_in'])) {
    echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
    exit;
}

// === 1. Load Composer & NepaliDate functions ===
$autoload_path = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload_path)) {
    echo json_encode(['success' => false, 'msg' => 'Composer autoload not found']);
    exit;
}
require_once $autoload_path;

// === 2. Include the helper functions ===
$nepali_file = '../Common/nepali_date.php';
if (!file_exists($nepali_file)) {
    echo json_encode(['success' => false, 'msg' => 'nepali_date.php not found']);
    exit;
}
require_once $nepali_file;

// === 3. DB Connection ===
require '../Common/connection.php';
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'msg' => 'DB connection failed']);
    exit;
}

// === 4. Input ===
if (empty($_POST['restaurant_id'])) {
    echo json_encode(['success' => false, 'msg' => 'Missing restaurant ID']);
    exit;
}
$restaurant_id = (int)$_POST['restaurant_id'];

// === 5. Fetch current expiry (BS format: YYYY-MM-DD) ===
$stmt = $conn->prepare("SELECT expiry_date FROM restaurants WHERE id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'msg' => 'Prepare failed (select)']);
    exit;
}
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$res = $stmt->get_result();

if (!$row = $res->fetch_assoc()) {
    $stmt->close();
    echo json_encode(['success' => false, 'msg' => 'Restaurant not found']);
    exit;
}
$old_expiry_bs = $row['expiry_date']; // e.g. 2081-07-15
$stmt->close();

// === 6. Add 1 year (only year, keep month & day) ===
list($year, $month, $day) = explode('-', $old_expiry_bs);
$new_year = (int)$year + 1;
$new_expiry_bs = sprintf("%04d-%02d-%02d", $new_year, $month, $day);

// === 7. Update restaurant ===
$upd = $conn->prepare("UPDATE restaurants SET expiry_date = ?, is_trial = 0 WHERE id = ?");
if (!$upd) {
    echo json_encode(['success' => false, 'msg' => 'Prepare failed (update)']);
    exit;
}
$upd->bind_param("si", $new_expiry_bs, $restaurant_id);
if (!$upd->execute()) {
    $err = $upd->error;
    $upd->close();
    echo json_encode(['success' => false, 'msg' => "Update failed: $err"]);
    exit;
}
$upd->close();

// === 8. Get current Nepali datetime using helper function ===
$now_bs = nepali_date_time(); // Returns: 2081-07-20 14:30:25

// === 9. Insert into payment_history ===
$ins = $conn->prepare("
    INSERT INTO payment_history 
    (restaurant_id, old_expiry_date, new_expiry_date, created_at) 
    VALUES (?, ?, ?, ?)
");
if (!$ins) {
    echo json_encode(['success' => false, 'msg' => 'Prepare failed (insert)']);
    exit;
}
$ins->bind_param("isss", $restaurant_id, $old_expiry_bs, $new_expiry_bs, $now_bs);
if (!$ins->execute()) {
    $err = $ins->error;
    $ins->close();
    echo json_encode(['success' => false, 'msg' => "Insert failed: $err"]);
    exit;
}
$ins->close();

$conn->close();
echo json_encode(['success' => true]);