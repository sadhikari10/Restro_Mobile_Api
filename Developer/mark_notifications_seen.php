<?php
session_start();
require '../Common/connection.php';
// require '../Common/nepali_date.php';

if(empty($_SESSION['developer_logged_in'])) exit;

// Mark all unseen notifications as seen (counter)
$conn->query("UPDATE notifications SET seen=1 WHERE seen=0");
$conn->close();
?>