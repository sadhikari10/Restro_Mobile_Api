<?php
session_start();
require '../Common/connection.php';
// require '../Common/nepali_date.php';

if(empty($_SESSION['developer_logged_in'])) exit;

$today = date('Y-m-d');

$sql = "SELECT r.id AS restaurant_id, r.name AS restaurant_name, r.expiry_date, u.username, u.phone, r.is_trial
        FROM restaurants r
        INNER JOIN users u ON r.id = u.restaurant_id
        WHERE u.role='admin'";
$result = $conn->query($sql);

while($row = $result->fetch_assoc()){
    $daysLeft = (strtotime($row['expiry_date']) - strtotime($today)) / (60*60*24);

    $notificationsToCreate = [];
    if ($row['is_trial'] && $daysLeft==15) $notificationsToCreate[]=['type'=>'15_days','message'=>"{$row['phone']}, {$row['restaurant_name']}, {$row['username']} trial expires in 15 days"];
    if ($row['is_trial'] && $daysLeft==3) $notificationsToCreate[]=['type'=>'3_days','message'=>"{$row['phone']}, {$row['restaurant_name']}, {$row['username']} trial expires in 3 days"];
    if ($row['is_trial'] && $daysLeft<0) $notificationsToCreate[]=['type'=>'expired','message'=>"{$row['phone']}, {$row['restaurant_name']}, {$row['username']} trial expired"];
    if (!$row['is_trial']) $notificationsToCreate[]=['type'=>'renewed','message'=>"{$row['phone']}, {$row['restaurant_name']}, {$row['username']} renewed subscription"];

    foreach($notificationsToCreate as $n){
        $check = $conn->prepare("SELECT id FROM notifications WHERE restaurant_id=? AND type=?");
        $check->bind_param("is",$row['restaurant_id'],$n['type']);
        $check->execute();
        $check->store_result();
        if($check->num_rows==0){
            $insert=$conn->prepare("INSERT INTO notifications (restaurant_id,type,message) VALUES (?,?,?)");
            $insert->bind_param("iss",$row['restaurant_id'],$n['type'],$n['message']);
            $insert->execute();
            $insert->close();
        }
        $check->close();
    }
}

// Fetch all notifications with seen status
$sql2 = "SELECT id,message,seen FROM notifications ORDER BY created_at DESC";
$res = $conn->query($sql2);
$notifications = [];
while($row2=$res->fetch_assoc()) $notifications[]=$row2;

echo json_encode($notifications);
$conn->close();
?>