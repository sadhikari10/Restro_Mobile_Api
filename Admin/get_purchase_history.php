<?php
session_start();
require '../Common/connection.php';
header('Content-Type: application/json; charset=utf-8');

$purchase_id   = (int)($_GET['purchase_id'] ?? 0);
$restaurant_id = (int)($_GET['restaurant_id'] ?? $_SESSION['restaurant_id'] ?? 0);

if ($purchase_id <= 0 || $restaurant_id <= 0) {
    echo json_encode([]);
    exit;
}

// Fetch history
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
if (!$stmt) {
    echo json_encode([]);
    exit;
}

$stmt->bind_param("ii", $purchase_id, $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    // Remove seconds from timestamp
    $row['edited_at'] = preg_replace('/:\d{2}$/', '', $row['edited_at'] ?? '');
    
    // Ensure items_json is valid JSON
    $items = json_decode($row['items_json'] ?? '[]', true);
    if (!is_array($items)) $items = [];
    $row['items_json'] = json_encode($items, JSON_UNESCAPED_UNICODE);

    $history[] = $row;
}

$stmt->close();
echo json_encode($history, JSON_UNESCAPED_UNICODE);
exit;
?>
