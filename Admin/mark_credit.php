<?php
// Admin/mark_credit.php - Process marking an order as Credit/Udhar (for Admin)
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['restaurant_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$restaurant_id = (int)$_SESSION['restaurant_id'];
$order_id = (int)($_POST['order_id'] ?? 0);
$customer_id = (int)($_POST['customer_id'] ?? 0);
$net_total = (float)($_POST['net_total'] ?? 0);
$discount = (float)($_POST['discount'] ?? 0);
$paid_today = max(0, (float)($_POST['paid_today'] ?? 0));
$selected_payment_method = in_array($_POST['payment_method'] ?? '', ['cash', 'online']) ? $_POST['payment_method'] : 'cash';

if ($order_id <= 0 || $customer_id <= 0 || $net_total <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

// Get current user ID
$user_id = 0;
if (isset($_SESSION['username'])) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param('s', $_SESSION['username']);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $user_id = (int)($res['id'] ?? 0);
    $stmt->close();
}

// Fetch order details
$stmt = $conn->prepare("SELECT items, table_number, created_at FROM orders WHERE id = ? AND restaurant_id = ?");
$stmt->bind_param('ii', $order_id, $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    $stmt->close();
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}
$order = $result->fetch_assoc();
$stmt->close();

$bs_datetime = nepali_date_time();

// Build detailed items and subtotal
$items = json_decode($order['items'], true) ?? [];
$detailed_items = [];
$subtotal = 0;

foreach ($items as $it) {
    $item_id = (int)($it['item_id'] ?? 0);
    $qty = (int)($it['quantity'] ?? 1);
    $notes = $it['notes'] ?? '';

    if ($item_id > 0) {
        $stmt2 = $conn->prepare("SELECT item_name, price FROM menu_items WHERE id = ? AND restaurant_id = ?");
        $stmt2->bind_param('ii', $item_id, $restaurant_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

        $price = (float)($res2['price'] ?? 0);
        $name = $res2['item_name'] ?? 'Unknown Item';
    } else {
        $price = 0;
        $name = 'Unknown';
    }

    $amt = $price * $qty;
    $subtotal += $amt;

    $detailed_items[] = [
        'name' => $name,
        'qty' => $qty,
        'price' => $price,
        'amt' => $amt,
        'notes' => $notes
    ];
}

$amount_after_discount = $subtotal - $discount;

// Fetch charges and VAT
$charges = [];
$total_other_charges = 0;
$vat_percent = 0;

$stmt_charges = $conn->prepare("SELECT name, percent FROM restaurant_charges WHERE restaurant_id = ?");
$stmt_charges->bind_param('i', $restaurant_id);
$stmt_charges->execute();
$charges_result = $stmt_charges->get_result();

while ($row = $charges_result->fetch_assoc()) {
    $name_lower = strtolower(trim($row['name']));
    $percent = (float)$row['percent'];

    if ($name_lower === 'vat') {
        $vat_percent = $percent;
    } else {
        $amt = $amount_after_discount * ($percent / 100);
        $total_other_charges += $amt;
        $charges[] = [
            'name' => $row['name'],
            'percent' => $percent,
            'amount' => $amt
        ];
    }
}
$stmt_charges->close();

$taxable = $amount_after_discount + $total_other_charges;
$vat_amount = $vat_percent > 0 ? $taxable * ($vat_percent / 100) : 0;
$final_net_total = $taxable + $vat_amount;

// bill_details JSON
$bill_details = [
    'order_date' => $order['created_at'],
    'credit_date' => $bs_datetime,
    'table_number' => $order['table_number'],
    'items' => $detailed_items,
    'subtotal' => $subtotal,
    'discount' => $discount,
    'other_charges' => $charges,
    'taxable' => $taxable,
    'vat_percent' => $vat_percent,
    'vat_amount' => $vat_amount,
    'net_total' => $final_net_total,
    'paid_today' => $paid_today,
    'total_due' => $final_net_total - $paid_today,
    'note' => 'Credit Order',
    'marked_by' => $_SESSION['username'] ?? 'User'
];

$bill_details_json = json_encode($bill_details, JSON_UNESCAPED_UNICODE);

// Determine payment method for transaction
if ($paid_today > 0) {
    $transaction_payment_method = $selected_payment_method; // 'cash' or 'online'
} else {
    $transaction_payment_method = 'credit';
}

$notes = "Consumed: Rs. " . number_format($final_net_total, 2);
if ($paid_today > 0) {
    $notes .= " | Paid: Rs. " . number_format($paid_today, 2);
} else {
    $notes .= " | Full Credit";
}

$conn->autocommit(false);

try {
    // 1. Update order status to 'credit'
    $stmt = $conn->prepare("UPDATE orders SET status = 'credit', updated_at = ? WHERE id = ?");
    $stmt->bind_param('si', $bs_datetime, $order_id);
    $stmt->execute();
    $stmt->close();

    // 2. Insert transaction (amount = paid_today only)
    $stmt = $conn->prepare("
        INSERT INTO customer_transactions 
        (customer_id, restaurant_id, order_id, type, amount, discount, payment_method, notes, 
         transaction_bs_date, printed_by, created_by, bill_details)
        VALUES (?, ?, ?, 'consumption', ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'iiiddsssiss',
        $customer_id,
        $restaurant_id,
        $order_id,
        $paid_today,
        $discount,
        $transaction_payment_method,
        $notes,
        $bs_datetime,
        $_SESSION['username'],
        $user_id,
        $bill_details_json
    );
    $stmt->execute();
    $stmt->close();

    // 3. Update customer totals
    $stmt = $conn->prepare("
        UPDATE customers 
        SET total_consumed = total_consumed + ?,
            total_paid = total_paid + ?,
            remaining_due = remaining_due + ? - ?,
            updated_by = ?
        WHERE id = ? AND restaurant_id = ?
    ");
    $stmt->bind_param('ddddiii', $final_net_total, $paid_today, $final_net_total, $paid_today, $user_id, $customer_id, $restaurant_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Order successfully marked as Credit!']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

$conn->autocommit(true);
?>