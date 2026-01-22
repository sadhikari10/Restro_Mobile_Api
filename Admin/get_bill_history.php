<?php
// Admin/get_bill_history.php
ob_start();
session_start();
require '../Common/connection.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

// Security check
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['restaurant_id'])) {
    echo json_encode([]);
    exit;
}

$restaurant_id = (int)$_SESSION['restaurant_id'];
$bill_id       = (int)($_GET['bill_id'] ?? 0);

if ($bill_id <= 0) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT 
            COALESCE(u.username, CONCAT('User ID ', h.edited_by)) AS editor_name,
            u.email AS editor_email,
            h.edited_at,
            h.edit_reason,
            h.items_json,
            h.purchased_from,
            h.purchase_date
        FROM general_bill_history h
        LEFT JOIN users u ON h.edited_by = u.id
        WHERE h.general_bill_id = ? AND h.restaurant_id = ?
        ORDER BY h.edited_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $bill_id, $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    // Remove seconds from time: 14:30:22 → 14:30
    if (!empty($row['edited_at'])) {
        $row['edited_at'] = preg_replace('/:\d{2}$/', '', $row['edited_at']);
    }
    $history[] = $row;
}
$stmt->close();

echo json_encode($history, JSON_UNESCAPED_UNICODE);
exit;
?>