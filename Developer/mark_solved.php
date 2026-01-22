<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['developer_logged_in'])) {
    echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
    exit;
}

require '../Common/connection.php';

$id = intval($_POST['id'] ?? 0);
$type = strtolower(trim($_POST['type'] ?? ''));

// 🧠 Debug log (optional: you can comment this out later)
error_log("mark_solved.php called with id=$id, type=$type");

if ($id <= 0 || !in_array($type, ['issue', 'suggestion'], true)) {
    echo json_encode(['success' => false, 'msg' => "Invalid request: id=$id type=$type"]);
    exit;
}

try {
    // Select correct table
    $table = ($type === 'issue') ? 'issues' : 'suggestions';

    $stmt = $conn->prepare("UPDATE $table SET reviewed = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'msg' => 'No rows updated (maybe already reviewed?)']);
    }

    $stmt->close();
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'msg' => 'Error: ' . $e->getMessage()]);
}
?>
