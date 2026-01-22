<?php
// Admin/get_bill.php
ob_start();
session_start();
require '../Common/connection.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

// Security check
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['restaurant_id'])) {
    echo json_encode(null);
    exit;
}

$restaurant_id = (int)$_SESSION['restaurant_id'];
$bill_id       = (int)($_GET['bill_id'] ?? 0);

if ($bill_id <= 0) {
    echo json_encode(null);
    exit;
}

$stmt = $conn->prepare("
    SELECT purchased_from, purchase_date, items_json
    FROM general_bill
    WHERE id = ? AND restaurant_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $bill_id, $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(null);
}

$stmt->close();
exit;
?>