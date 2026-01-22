<?php
// Fixed: bill.php
// Changes: 
// - After "Mark as Paid", STAY on same page (no redirect)
// - Show success message + updated bill view (with new bill number)
// - Disable form after payment
// - Print button remains active
// - Printed time now shown in Nepali (BS) date format (e.g., 2082-07-18 14:30)

session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

date_default_timezone_set('Asia/Kathmandu');

// Define functions globally
function numberToWordsWithPaisa($amount) {
    $rupees = (int)$amount;
    $paisa = round(($amount - $rupees) * 100);

    $ones = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine", "Ten",
             "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen"];
    $tens = ["", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety"];

    $words = "";
    if ($rupees == 0) $words = "Zero";
    else {
        $thousands = ["", "Thousand", "Million", "Billion"];
        $i = 0;
        do {
            $n = $rupees % 1000;
            if ($n != 0) $words = _convertHundreds($n, $ones, $tens) . " " . $thousands[$i] . " " . $words;
            $rupees = (int)($rupees / 1000);
            $i++;
        } while ($rupees > 0);
    }

    $words = ucfirst(trim($words));
    if ($paisa > 0) {
        $paisa_words = $paisa < 20 ? $ones[$paisa] : $tens[(int)($paisa / 10)] . ($paisa % 10 ? " " . $ones[$paisa % 10] : "");
        $words .= " Rupees and " . ucfirst($paisa_words) . " Paisa";
    } else {
        $words .= " Rupees";
    }
    return $words . " Only";
}
function _convertHundreds($n, $ones, $tens) {
    $str = "";
    if ($n > 99) $str .= $ones[(int)($n / 100)] . " Hundred ";
    $n %= 100;
    if ($n < 20) $str .= $ones[$n];
    else $str .= $tens[(int)($n / 10)] . ($n % 10 ? " " . $ones[$n % 10] : "");
    return trim($str);
}

if (empty($_SESSION['logged_in']) || empty($_SESSION['restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

$restaurant_id = $_SESSION['restaurant_id'];
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($order_id <= 0) die("Invalid order.");

$back_url = $_SERVER['HTTP_REFERER'] ?? 'orders.php';

$user_id = 0;
if (isset($_SESSION['username'])) {
    $stmt_user = $conn->prepare("SELECT id FROM users WHERE username = ? AND restaurant_id = ? LIMIT 1");
    $stmt_user->bind_param('si', $_SESSION['username'], $restaurant_id);
    $stmt_user->execute();
    $user_res = $stmt_user->get_result()->fetch_assoc();
    $user_id = (int)($user_res['id'] ?? 0);
    $stmt_user->close();
}

// === AJAX: Mark as Paid ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_mark_paid'])) {
    $discount = max(0, (float)($_POST['discount'] ?? 0));
    $payment_method = in_array($_POST['payment_method'] ?? '', ['cash', 'online']) ? $_POST['payment_method'] : 'cash';

    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND restaurant_id = ?");
    $stmt->bind_param('ii', $order_id, $restaurant_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $items = json_decode($order['items'], true) ?? [];
    $subtotal = 0.0;
    $stmt_item = $conn->prepare("SELECT price, item_name FROM menu_items WHERE id=? AND restaurant_id=?");
    $detailed_items = [];
    foreach ($items as $it) {
        $item_id = (int)($it['item_id'] ?? 0);
        $qty = (int)($it['quantity'] ?? 0);
        $notes = $it['notes'] ?? '';
        if ($item_id <= 0 || $qty <= 0) continue;

        $stmt_item->bind_param('ii', $item_id, $restaurant_id);
        $stmt_item->execute();
        $res_item = $stmt_item->get_result()->fetch_assoc();
        $price = (float)($res_item['price'] ?? 0);
        $name = $res_item['item_name'] ?? 'Unknown';
        $amt = $price * $qty;
        $subtotal += $amt;
        $detailed_items[] = ['name' => $name, 'qty' => $qty, 'price' => $price, 'amt' => $amt, 'notes' => $notes];
    }
    $stmt_item->close();

    $stmt_rest = $conn->prepare("SELECT name, address, pan_number FROM restaurants WHERE id = ?");
    $stmt_rest->bind_param('i', $restaurant_id);
    $stmt_rest->execute();
    $rest = $stmt_rest->get_result()->fetch_assoc();
    $stmt_rest->close();

    $stmt_phone = $conn->prepare("SELECT phone FROM users WHERE restaurant_id = ? AND role = 'admin' LIMIT 1");
    $stmt_phone->bind_param('i', $restaurant_id);
    $stmt_phone->execute();
    $phone_row = $stmt_phone->get_result()->fetch_assoc();
    $stmt_phone->close();
    $admin_phone = $phone_row['phone'] ?? 'N/A';

    $print_time_db = nepali_date_time(); // BS datetime for DB
    $bs_parts = explode(' ', $print_time_db);
    $bs_date = $bs_parts[0];
    $fiscal_year = get_fiscal_year($bs_date);

    $stmt_next = $conn->prepare("SELECT COALESCE(MAX(last_bill_number), 0) + 1 as next_bill FROM bill_counter WHERE restaurant_id = ? AND fiscal_year = ?");
    $stmt_next->bind_param('is', $restaurant_id, $fiscal_year);
    $stmt_next->execute();
    $next_res = $stmt_next->get_result()->fetch_assoc();
    $bill_number = (int)$next_res['next_bill'];
    $stmt_next->close();

    // === CALCULATION: subtotal → discount → apply charges on (subtotal - discount) ===
    $amount_after_discount = $subtotal - $discount;

    $charges_stmt = $conn->prepare("SELECT name, percent FROM restaurant_charges WHERE restaurant_id = ?");
    $charges_stmt->bind_param('i', $restaurant_id);
    $charges_stmt->execute();
    $charges_result = $charges_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $charges_stmt->close();

    $vat_percent = 0.0;
    $other_charges = [];
    $total_other_charge_amount = 0.0;

    foreach ($charges_result as $c) {
        $name = strtolower(trim($c['name']));
        $percent = (float)$c['percent'];

        if ($name === 'vat') {
            $vat_percent = $percent;
        } else {
            $amount = $amount_after_discount * ($percent / 100);
            $total_other_charge_amount += $amount;
            $other_charges[] = [
                'name' => $c['name'],
                'percent' => $percent,
                'amount' => $amount
            ];
        }
    }

    $amount_after_charges = $amount_after_discount + $total_other_charge_amount;
    $taxable = max(0, $amount_after_charges);
    $vat_amount = $vat_percent > 0 ? $taxable * ($vat_percent / 100) : 0;
    $net_total = $taxable + $vat_amount;

    $amount_in_words = "Nepalese " . numberToWordsWithPaisa($net_total);

    $bill_details = [
        'restaurant_name' => $rest['name'],
        'address' => $rest['address'],
        'admin_phone' => $admin_phone,
        'pan_number' => $rest['pan_number'],
        'bill_number' => $bill_number,
        'order_date' => $order['created_at'],
        'printed_date' => $print_time_db, // BS
        'table_number' => $order['table_number'],
        'items' => $detailed_items,
        'subtotal' => $subtotal,
        'discount' => $discount,
        'other_charges' => $other_charges,
        'taxable' => $taxable,
        'vat_percent' => $vat_percent,
        'vat_amount' => $vat_amount,
        'net_total' => $net_total,
        'amount_in_words' => $amount_in_words,
        'printed_by' => $_SESSION['username'] ?? 'Admin',
        'payment_method' => $payment_method
    ];
    $bill_json = json_encode($bill_details);

    $stmt_bc = $conn->prepare("
        INSERT INTO bill_counter 
        (restaurant_id, fiscal_year, last_bill_number, bill_printed_at, discount, payment_method, order_id, vat_amount, other_charges_amount, net_total, bill_details, printed_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt_bc->bind_param('isisdsidddsi', $restaurant_id, $fiscal_year, $bill_number, $print_time_db, $discount, $payment_method, $order_id, $vat_amount, $total_other_charge_amount, $net_total, $bill_json, $user_id);
    $bc_success = $stmt_bc->execute();
    $stmt_bc->close();

    $stmt_order = $conn->prepare("
        UPDATE orders 
        SET status = 'paid', 
            updated_at = ?
        WHERE id = ? AND restaurant_id = ?
    ");
    $stmt_order->bind_param('sii', $print_time_db, $order_id, $restaurant_id);
    $order_success = $stmt_order->execute();
    $stmt_order->close();

    if ($bc_success && $order_success) {
        // Format BS date for display (YYYY-MM-DD HH:MM)
        $bs_parts = explode(' ', $print_time_db);
        $bs_date = $bs_parts[0];
        $bs_time = substr($bs_parts[1], 0, 5);
        $bill_details['printed_date_display'] = "$bs_date $bs_time";

        echo json_encode([
            'success' => true, 
            'bill_number' => $bill_number,
            'bill_details' => $bill_details
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save bill']);
    }
    exit;
}

// === FETCH ORDER ===
$stmt = $conn->prepare("
    SELECT o.*, r.name as restaurant_name, r.address, r.pan_number, u.phone as admin_phone
    FROM orders o 
    JOIN restaurants r ON o.restaurant_id = r.id 
    JOIN users u ON r.id = u.restaurant_id AND u.role = 'admin'
    WHERE o.id = ? AND o.restaurant_id = ?
");
$stmt->bind_param('ii', $order_id, $restaurant_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$order) die("Order not found.");

$is_paid = $order['status'] === 'paid';
$bill_details = null;
$bill_data = null;
if ($is_paid) {
    $stmt_bc = $conn->prepare("SELECT *, last_bill_number AS bill_number FROM bill_counter WHERE restaurant_id = ? AND order_id = ?");
    $stmt_bc->bind_param('ii', $restaurant_id, $order_id);
    $stmt_bc->execute();
    $bill_data = $stmt_bc->get_result()->fetch_assoc();
    $stmt_bc->close();
    if ($bill_data) {
        $bill_details = json_decode($bill_data['bill_details'], true);
    }
}

// Interpret table_number
$table_display = '';
$table_num = $order['table_number'];
if (preg_match('/^\\d{7,10}$/', $table_num)) {
    $table_display = 'Phone: ' . $table_num;
} elseif (is_numeric($table_num) && strlen($table_num) <= 3) {
    $table_display = 'Table: ' . $table_num;
} else {
    $table_display = 'Customer: ' . $table_num;
}

// For unpaid or no json: calculate live
$subtotal = 0.0;
$detailed_items = [];
$discount = 0.0;
$payment_method_display = '—';
$print_time = nepali_date_time(); // BS for live preview
$bs_parts = explode(' ', $print_time);
$print_time = $bs_parts[0] . ' ' . substr($bs_parts[1], 0, 5); // YYYY-MM-DD HH:MM

$fiscal_year = get_fiscal_year($bs_parts[0]);

$other_charges = [];
$vat_percent = 0.0;
$vat_amount = 0.0;
$net_total = 0.0;
$amount_in_words = '';
$display_bill_number = null;
$total_other_charge_amount_local = 0.0;
$charges_list = [];

if (!$is_paid || !$bill_details) {
    $items = json_decode($order['items'], true) ?? [];
    $stmt_item = $conn->prepare("SELECT price, item_name FROM menu_items WHERE id=? AND restaurant_id=?");
    foreach ($items as $it) {
        $item_id = (int)($it['item_id'] ?? 0);
        $qty = (int)($it['quantity'] ?? 0);
        $notes = $it['notes'] ?? '';
        if ($item_id <= 0 || $qty <= 0) continue;

        $stmt_item->bind_param('ii', $item_id, $restaurant_id);
        $stmt_item->execute();
        $res_item = $stmt_item->get_result()->fetch_assoc();
        $price = (float)($res_item['price'] ?? 0);
        $name = $res_item['item_name'] ?? 'Unknown';
        $amt = $price * $qty;
        $subtotal += $amt;
        $detailed_items[] = ['name' => $name, 'qty' => $qty, 'price' => $price, 'amt' => $amt, 'notes' => $notes];
    }
    $stmt_item->close();

    $amount_after_discount = $subtotal - $discount;

    $charges_stmt = $conn->prepare("SELECT name, percent FROM restaurant_charges WHERE restaurant_id = ?");
    $charges_stmt->bind_param('i', $restaurant_id);
    $charges_stmt->execute();
    $charges_result = $charges_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $charges_stmt->close();

    foreach ($charges_result as $c) {
        $name = strtolower(trim($c['name']));
        $percent = (float)$c['percent'];

        if ($name === 'vat') {
            $vat_percent = $percent;
        } else {
            $amount = $amount_after_discount * ($percent / 100);
            $total_other_charge_amount_local += $amount;
            $other_charges[] = [
                'name' => $c['name'],
                'percent' => $percent,
                'amount' => $amount
            ];
            $charges_list[] = ['name' => $c['name'], 'percent' => $percent];
        }
    }

    $amount_after_charges = $amount_after_discount + $total_other_charge_amount_local;
    $taxable = max(0, $amount_after_charges);
    $vat_amount = $vat_percent > 0 ? $taxable * ($vat_percent / 100) : 0;
    $net_total = $taxable + $vat_amount;

    $amount_in_words = "Nepalese " . numberToWordsWithPaisa($net_total);

    $stmt_next = $conn->prepare("SELECT COALESCE(MAX(last_bill_number), 0) + 1 as next_bill FROM bill_counter WHERE restaurant_id = ? AND fiscal_year = ?");
    $stmt_next->bind_param('is', $restaurant_id, $fiscal_year);
    $stmt_next->execute();
    $next_res = $stmt_next->get_result()->fetch_assoc();
    $display_bill_number = (int)$next_res['next_bill'];
    $stmt_next->close();
} else {
    // Pull from bill_counter for paid orders
    $detailed_items = $bill_details['items'] ?? [];
    $subtotal = $bill_details['subtotal'] ?? 0;
    $discount = $bill_data['discount'] ?? 0;
    $other_charges = $bill_details['other_charges'] ?? [];
    $vat_percent = $bill_details['vat_percent'] ?? 0;
    $vat_amount = $bill_details['vat_amount'] ?? 0;
    $net_total = $bill_details['net_total'] ?? 0;
    $amount_in_words = $bill_details['amount_in_words'] ?? '';
    $payment_method_display = $bill_data['payment_method'] ?? '—';
    $display_bill_number = $bill_data['bill_number'] ?? null;
    $total_other_charge_amount_local = array_sum(array_column($other_charges, 'amount'));
    $charges_list = array_map(fn($c) => ['name' => $c['name'], 'percent' => $c['percent']], $other_charges);

    // Use the saved printed time for paid bills
    $print_time_db = $bill_data['bill_printed_at'];
    $bs_parts = explode(' ', $print_time_db);
    $bs_date = $bs_parts[0];
    $bs_time = substr($bs_parts[1], 0, 5);
    $print_time = "$bs_date $bs_time";
}

$printed_by = $bill_details['printed_by'] ?? ($_SESSION['username'] ?? 'Admin');
$order_date_display = date('d/m/Y h:i A', strtotime($order['created_at']));
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bill <?= htmlspecialchars($display_bill_number) ?> - <?= htmlspecialchars($order['restaurant_name']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { font-family: 'Courier New', monospace; background: #f8f9fa; }
.bill { max-width: 350px; margin: 20px auto; padding: 15px; border: 2px dashed #000; background: #fff; }
h5 { text-align: center; margin: 0; font-weight: bold; }
.dotted { border-bottom: 1px dotted #000; margin: 8px 0; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.text-left { text-align: left; }
.small { font-size: 0.8rem; }
.smaller { font-size: 0.7rem; }
.net-total { font-size: 0.7rem; }
table.small th, table.small td { padding: 0.25rem 0.5rem; }
.no-print { margin: 15px 0; text-align: center; }
@media print {
    body { background: white; }
    .no-print, .alert-success { display: none !important; }
    .bill { border: none; }
}
#paidSuccess { display: none; margin: 15px auto; max-width: 350px; }
</style>
</head>
<body>

<div class="bill" id="billSection">
    <h5><?= htmlspecialchars($order['restaurant_name']) ?></h5>
    <div class="small text-center">
        <?= nl2br(htmlspecialchars($order['address'] ?? '')) ?><br>
        Phone: <?= htmlspecialchars($order['admin_phone'] ?? 'N/A') ?><br>
        PAN: <?= htmlspecialchars($order['pan_number'] ?? 'N/A') ?>
    </div>
    <div class="smaller text-left mt-1">
        <strong><?= htmlspecialchars($table_display) ?></strong><br>
        <strong>Bill No: <span id="billNoDisplay"><?= htmlspecialchars($display_bill_number) ?></span></strong><br>
        <strong>Order Date: <?= $order_date_display ?></strong>
    </div>
    <div class="dotted"></div>

    <table width="100%" class="small">
        <thead>
            <tr>
                <th width="5%">S.N.</th>
                <th width="50%">Item</th>
                <th width="8%">Qty</th>
                <th width="15%">Rate</th>
                <th width="22%">Amount</th>
            </tr>
        </thead>
        <tbody id="itemsBody">
            <?php $sn = 1; foreach ($detailed_items as $item): ?>
            <tr>
                <td><?= $sn++ ?></td>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td class="text-right"><?= $item['qty'] ?></td>
                <td class="text-right"><?= number_format($item['price'], 2) ?></td>
                <td class="text-right"><?= number_format($item['amt'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr><td colspan="5" class="dotted"></td></tr>

            <tr><td colspan="4" class="text-right">Subtotal</td><td class="text-right"><strong><?= number_format($subtotal, 2) ?></strong></td></tr>

            <tr id="discountRow" style="<?= $discount > 0 ? '' : 'display:none;' ?>">
                <td colspan="4" class="text-right">Discount</td>
                <td class="text-right" id="discountAmt"><?= number_format($discount, 2) ?></td>
            </tr>

            <tbody id="chargesBody">
                <?php foreach ($other_charges as $chg): ?>
                <tr>
                    <td colspan="4" class="text-right"><?= htmlspecialchars($chg['name']) ?> (<?= $chg['percent'] ?>%)</td>
                    <td class="text-right"><?= number_format($chg['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>

            <tr><td colspan="4" class="text-right">Taxable Amount</td><td class="text-right" id="taxableAmt"><?= number_format($subtotal - $discount + $total_other_charge_amount_local, 2) ?></td></tr>

            <?php if ($vat_percent > 0): ?>
            <tr>
                <td colspan="4" class="text-right">VAT (<?= $vat_percent ?>%)</td>
                <td class="text-right" id="vatAmt"><?= number_format($vat_amount, 2) ?></td>
            </tr>
            <?php endif; ?>

            <tr><td colspan="5" class="dotted"></td></tr>

            <tr>
                <td colspan="4" class="text-right net-total"><strong>Net Total</strong></td>
                <td class="text-right net-total"><strong id="finalTotal"><?= number_format($net_total, 2) ?></strong></td>
            </tr>
        </tbody>
    </table>

    <div class="dotted"></div>

    <div class="small">
        <strong>Amount in Words:</strong><br>
        <span id="words"><?= $amount_in_words ?></span><br><br>
        <strong>Printed By:</strong> <span id="printedBy"><?= htmlspecialchars($printed_by) ?></span><br>
        <strong>Printed Time:</strong> <span id="printTime"><?= $print_time ?></span><br>
        <span id="paymentInfo" style="<?= $is_paid ? '' : 'display:none;' ?>">
            <strong>Payment:</strong> <span id="paymentMethod"><?= ucfirst(htmlspecialchars($payment_method_display)) ?></span>
        </span>
    </div>
</div>

<!-- Controls -->
<div class="no-print container">
    <div class="row">
        <div class="col-md-6 mx-auto">
            <div id="billFormContainer">
                <?php if (!$is_paid): ?>
                <form id="billForm" class="p-3 border rounded bg-light" onsubmit="return false;">
                    <div class="mb-2">
                        <label class="form-label small">Discount (Rs)</label>
                        <input type="number" id="discount" name="discount" class="form-control form-control-sm" min="0" step="0.01"
                            value="<?= $discount > 0 ? number_format($discount, 2, '.', '') : '' ?>"
                            placeholder="0.00">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Payment Method</label>
                        <select id="payment_method" class="form-select form-select-sm">
                            <option value="cash">Cash</option>
                            <option value="online">Online</option>
                        </select>
                    </div>
                    <button type="button" id="markPaidBtn" class="btn btn-success btn-sm w-100">Mark as Paid & Generate Bill</button>
                </form>
                <?php else: ?>
                <div class="alert alert-success text-center">Bill <?= htmlspecialchars($display_bill_number) ?> Paid (<?= ucfirst($payment_method_display) ?>)</div>
                <?php endif; ?>
            </div>

            <div class="text-center mt-2">
                <button onclick="window.print()" class="btn btn-primary btn-sm">Print</button>
                <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-secondary btn-sm">Back</a>
            </div>
        </div>
    </div>
</div>

<div id="paidSuccess" class="alert alert-success text-center" style="display:none;">
    <strong>Bill Paid!</strong> Bill <span id="finalBillNo"></span> generated.
</div>

<script>
<?php if (!$is_paid): ?>
const baseSubtotal = <?= json_encode((float)$subtotal) ?>;
const chargesList = <?= json_encode($charges_list) ?>;
const vatPercent = <?= json_encode($vat_percent) ?>;

function calculateLive() {
    const discountInput = document.getElementById('discount');
    let discount = parseFloat(discountInput.value) || 0;
    if (discount > baseSubtotal) {
        discount = baseSubtotal;
        discountInput.value = discount.toFixed(2);
    }

    const afterDiscount = baseSubtotal - discount;
    let totalCharges = 0;
    const chargesBody = document.getElementById('chargesBody');
    chargesBody.innerHTML = '';

    chargesList.forEach(c => {
        const chargeAmt = afterDiscount * (c.percent / 100);
        totalCharges += chargeAmt;
        const row = document.createElement('tr');
        row.innerHTML = `<td colspan="4" class="text-right">${c.name} (${c.percent}%)</td><td class="text-right">${chargeAmt.toFixed(2)}</td>`;
        chargesBody.appendChild(row);
    });

    const taxable = afterDiscount + totalCharges;
    const vat = vatPercent > 0 ? taxable * (vatPercent / 100) : 0;
    const net = taxable + vat;

    const discountRow = document.getElementById('discountRow');
    const discountAmt = document.getElementById('discountAmt');
    if (discountRow && discountAmt) {
        if (discount > 0) {
            discountRow.style.display = '';
            discountAmt.innerText = discount.toFixed(2);
        } else {
            discountRow.style.display = 'none';
        }
    }

    document.getElementById('taxableAmt').innerText = taxable.toFixed(2);
    const vatAmt = document.getElementById('vatAmt');
    if (vatAmt) vatAmt.innerText = vat.toFixed(2);
    document.getElementById('finalTotal').innerText = net.toFixed(2);
    document.getElementById('words').innerText = 'Nepalese ' + numberToWordsWithPaisa(net);
}

document.addEventListener('DOMContentLoaded', calculateLive);
document.getElementById('discount')?.addEventListener('input', calculateLive);
<?php endif; ?>

document.getElementById('markPaidBtn')?.addEventListener('click', function() {
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const payment_method = document.getElementById('payment_method').value;

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_mark_paid=1&discount=' + discount + '&payment_method=' + payment_method
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const bd = data.bill_details;
            // Update bill number
            document.getElementById('billNoDisplay').innerText = bd.bill_number;
            document.getElementById('finalBillNo').innerText = bd.bill_number;

            // Update values
            document.getElementById('discountAmt').innerText = parseFloat(bd.discount).toFixed(2);
            if (bd.discount > 0) {
                document.getElementById('discountRow').style.display = '';
            }

            // Rebuild charges
            const chargesBody = document.getElementById('chargesBody');
            chargesBody.innerHTML = '';
            bd.other_charges.forEach(c => {
                const row = document.createElement('tr');
                row.innerHTML = `<td colspan="4" class="text-right">${c.name} (${c.percent}%)</td><td class="text-right">${parseFloat(c.amount).toFixed(2)}</td>`;
                chargesBody.appendChild(row);
            });

            document.getElementById('taxableAmt').innerText = parseFloat(bd.taxable).toFixed(2);
            const vatAmt = document.getElementById('vatAmt');
            if (vatAmt) vatAmt.innerText = parseFloat(bd.vat_amount).toFixed(2);
            document.getElementById('finalTotal').innerText = parseFloat(bd.net_total).toFixed(2);
            document.getElementById('words').innerText = bd.amount_in_words;
            document.getElementById('printedBy').innerText = bd.printed_by;
            document.getElementById('printTime').innerText = bd.printed_date_display;
            document.getElementById('paymentMethod').innerText = bd.payment_method.charAt(0).toUpperCase() + bd.payment_method.slice(1);
            document.getElementById('paymentInfo').style.display = '';

            // Hide form, show success
            document.getElementById('billForm').style.display = 'none';
            document.getElementById('paidSuccess').style.display = 'block';
        } else {
            alert('Error: ' + (data.error || 'Failed to process'));
        }
    })
    .catch(() => alert('Error saving bill.'));
});

function numberToWordsWithPaisa(amount) {
    const rupees = Math.floor(amount);
    const paisa = Math.round((amount - rupees) * 100);
    const ones = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine", "Ten",
                  "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen"];
    const tens = ["", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety"];

    let words = rupees === 0 ? "Zero" : "";
    if (rupees > 0) {
        let i = 0, temp = rupees;
        const thousands = ["", "Thousand", "Million", "Billion"];
        do {
            const n = temp % 1000;
            if (n != 0) words = _convertHundreds(n, ones, tens) + " " + thousands[i] + " " + words;
            temp = Math.floor(temp / 1000); i++;
        } while (temp > 0);
    }
    words = words.trim();
    words = words ? words.charAt(0).toUpperCase() + words.slice(1) + " Rupees" : "Zero Rupees";

    if (paisa > 0) {
        const pwords = paisa < 20 ? ones[paisa] : tens[Math.floor(paisa / 10)] + (paisa % 10 ? " " + ones[paisa % 10] : "");
        words += " and " + (pwords.charAt(0).toUpperCase() + pwords.slice(1)) + " Paisa";
    }
    return words + " Only";
}

function _convertHundreds(n, ones, tens) {
    let str = "";
    if (n > 99) str += ones[Math.floor(n / 100)] + " Hundred ";
    n %= 100;
    if (n < 20) str += ones[n];
    else str += tens[Math.floor(n / 10)] + (n % 10 ? " " + ones[n % 10] : "");
    return str.trim();
}
</script>

</body>
</html>