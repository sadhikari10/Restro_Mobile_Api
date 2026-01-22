<?php
session_start();
require '../Common/connection.php';
// require '../Common/nepali_date.php';


if(empty($_SESSION['developer_logged_in'])) exit;

$sql = "SELECT id, message FROM notifications WHERE seen=0 ORDER BY created_at DESC";
$result = $conn->query($sql);

$notifications = [];
if($result->num_rows > 0){
    while($row = $result->fetch_assoc()){
        $notifications[] = $row;
    }
}

echo json_encode($notifications);
$conn->close();
?>