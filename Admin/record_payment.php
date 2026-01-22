<?php
// Admin/record_payment.php - Record Credit Payment (for Admin)

ob_start(); // Prevent any accidental output
session_start();

require '../Common/connection.php';
require '../Common/nepali_date.php';

ob_end_clean(); // Clear any output buffer

header('Content-Type: application/json');

// Security check
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['restaurant_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request or unauthorized']);
    exit;
}

$restaurant_id = (int)$_SESSION['restaurant_id'];
$customer_id   = (int)($_POST['customer_id'] ?? 0);
$amount        = max(0, (float)($_POST['amount'] ?? 0));
$payment_method = in_array($_POST['payment_method'] ?? '', ['cash', 'online']) ? $_POST['payment_method'] : 'cash';

if ($customer_id <= 0 || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid customer or amount']);
    exit;
}

// Get current logged-in user info
$user_id = 0;
$printed_by = 'Admin';

if (!empty($_SESSION['username'])) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $_SESSION['username']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $user_id = (int)$row['id'];
        $printed_by = $_SESSION['username'];
    }
    $stmt->close();
}

$bs_datetime = nepali_date_time();
$notes = "Payment: Rs. " . number_format($amount, 2) . " via " . ucfirst($payment_method);
$bill_details = json_encode([]); // No bill details for manual payments

$conn->autocommit(false);

try {
    // 1. Insert payment transaction
    $stmt = $conn->prepare("
        INSERT INTO customer_transactions 
        (customer_id, restaurant_id, order_id, type, amount, discount, payment_method, notes,
         transaction_bs_date, printed_by, created_by, bill_details)
        VALUES (?, ?, NULL, 'payment', ?, 0.00, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        'iidssssis',
        $customer_id,
        $restaurant_id,
        $amount,
        $payment_method,
        $notes,
        $bs_datetime,
        $printed_by,
        $user_id,
        $bill_details
    );
    $stmt->execute();
    $stmt->close();

    // 2. Update customer totals
    $stmt = $conn->prepare("
        UPDATE customers 
        SET total_paid = total_paid + ?,
            remaining_due = remaining_due - ?,
            updated_by = ?,
            updated_bs_datetime = ?
        WHERE id = ? AND restaurant_id = ?
    ");

    $stmt->bind_param('ddisii', $amount, $amount, $user_id, $bs_datetime, $customer_id, $restaurant_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Payment recorded successfully']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

$conn->autocommit(true);
exit;
?>