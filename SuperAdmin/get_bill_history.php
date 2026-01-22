<?php
// SuperAdmin/get_bill_history.php → FINAL CLEAN (returns direct array)
ob_start();
session_start();
require '../Common/connection.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$bill_id       = (int)($_GET['bill_id'] ?? 0);
$restaurant_id = (int)($_GET['restaurant_id'] ?? 0);

if ($bill_id <= 0 || $restaurant_id <= 0) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT 
            COALESCE(u.username, CONCAT('User ID ', h.edited_by)) AS editor_name,
            h.edited_at,
            COALESCE(NULLIF(TRIM(h.edit_reason), ''), 'No reason provided') AS edit_reason,
            h.purchased_from,
            h.purchase_date,
            h.items_json
        FROM general_bill_history h
        LEFT JOIN users u ON h.edited_by = u.id
        WHERE h.general_bill_id = ?
          AND h.restaurant_id = ?
        ORDER BY h.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $bill_id, $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    $row['edited_at'] = preg_replace('/:\d{2}$/', '', $row['edited_at'] ?? '');
    $history[] = $row;
}
$stmt->close();

echo json_encode($history, JSON_UNESCAPED_UNICODE);
exit;
?>