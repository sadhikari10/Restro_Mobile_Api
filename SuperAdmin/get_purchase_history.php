<?php
ob_start();
session_start();
require '../Common/connection.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$purchase_id   = (int)($_GET['purchase_id'] ?? 0);
$restaurant_id = (int)($_GET['restaurant_id'] ?? 0);

if ($purchase_id <= 0 || $restaurant_id <= 0) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT 
            COALESCE(u.username, CONCAT('User ID ', h.edited_by)) AS editor_name,
            u.email AS editor_email,
            h.edited_at,
            h.edit_reason,
            h.items_json,
            h.net_total,
            h.transaction_date,
            h.company_name
        FROM purchase_history h
        LEFT JOIN users u ON h.edited_by = u.id
        WHERE h.purchase_id = ? AND h.restaurant_id = ?
        ORDER BY h.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $purchase_id, $restaurant_id);
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