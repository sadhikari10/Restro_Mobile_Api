<?php
// Admin/credit_orders.php - Current Orders with "Mark Credit" option for Admin
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

$restaurant_id = (int)$_SESSION['restaurant_id'];
$user_id = 0;
if (isset($_SESSION['username'])) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param('s', $_SESSION['username']);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $user_id = (int)($res['id'] ?? 0);
    $stmt->close();
}

// Fetch restaurant name
$stmt = $conn->prepare("SELECT name FROM restaurants WHERE id = ?");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$res = $stmt->get_result();
$restaurant = $res->fetch_assoc();
$stmt->close();
$restaurant_name = $restaurant['name'] ?? 'Branch';

// === AJAX: Get Restaurant Charges & VAT Percent ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_get_charges'])) {
    header('Content-Type: application/json');

    $stmt = $conn->prepare("SELECT name, percent FROM restaurant_charges WHERE restaurant_id = ?");
    $stmt->bind_param('i', $restaurant_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $charges = [];
    $vat_percent = 0.0;

    while ($row = $result->fetch_assoc()) {
        $name = strtolower(trim($row['name']));
        $percent = (float)$row['percent'];

        if ($name === 'vat') {
            $vat_percent = $percent;
        } else {
            $charges[] = [
                'name' => $row['name'],
                'percent' => $percent
            ];
        }
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'charges' => $charges,
        'vat_percent' => $vat_percent
    ]);
    exit;
}

// === AJAX: Search Customers ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_search_customer'])) {
    header('Content-Type: application/json');
    $query = trim($_POST['query'] ?? '');
    if (strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT id, name, phone, remaining_due 
        FROM customers 
        WHERE restaurant_id = ? AND (name LIKE ? OR phone LIKE ?)
        ORDER BY name LIMIT 10
    ");
    $like = '%' . $query . '%';
    $stmt->bind_param('iss', $restaurant_id, $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $customers = [];
    while ($row = $result->fetch_assoc()) {
        $phone = $row['phone'] ?: 'No phone';
        $due = number_format((float)$row['remaining_due'], 2);
        $customers[] = [
            'id' => (int)$row['id'],
            'name' => htmlspecialchars($row['name']),
            'phone' => htmlspecialchars($phone),
            'due' => $due,
            'label' => htmlspecialchars($row['name']) . " ($phone) - Due: Rs. $due"
        ];
    }
    $stmt->close();
    echo json_encode($customers);
    exit;
}

// Fetch all current non-paid orders
$query = "SELECT * FROM orders WHERE restaurant_id = ? AND status IN ('preparing', 'served') ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Current Orders - <?= htmlspecialchars($restaurant_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../admin.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .table { background: white; }
        .back-btn { margin: 1rem 0; }
        .dropdown-menu { max-height: 250px; overflow-y: auto; }
        .dropdown-item { cursor: pointer; }
        .dropdown-item:hover { background-color: #f8f9fa; }
    </style>
</head>
<body>


<div class="container py-5">
    <div class="card shadow-lg">
        <div class="card-header bg-primary text-white text-center">
            <h4 class="mb-0">Current Orders - <?= htmlspecialchars($restaurant_name) ?></h4>
        </div>
        <div class="card-body">
            <div class="text-start back-btn">
                <a href="dashboard.php" class="btn btn-light border">&larr; Back</a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Table</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($row['table_number']) ?></strong></td>
                                    <td style="text-align:left; max-width: 300px;">
                                        <?php
                                        $items = json_decode($row['items'], true) ?? [];
                                        foreach ($items as $item) {
                                            $name = "Item";
                                            if (!empty($item['item_id'])) {
                                                $stmt2 = $conn->prepare("SELECT item_name FROM menu_items WHERE id = ?");
                                                $stmt2->bind_param("i", $item['item_id']);
                                                $stmt2->execute();
                                                $res2 = $stmt2->get_result();
                                                if ($res2->num_rows > 0) {
                                                    $name = $res2->fetch_assoc()['item_name'];
                                                }
                                                $stmt2->close();
                                            }
                                            $qty = $item['quantity'] ?? 1;
                                            $notes = !empty($item['notes']) ? " – <em>" . htmlspecialchars($item['notes']) . "</em>" : "";
                                            echo "<div><strong>$name</strong> × $qty$notes</div>";
                                        }
                                        ?>
                                    </td>
                                    <td><strong>Rs <?= number_format($row['total_amount'], 2) ?></strong></td>
                                    <td><?= htmlspecialchars($row['created_at']) ?></td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="bill.php?order_id=<?= $row['id'] ?>" class="btn btn-success btn-sm">
                                                View Bill
                                            </a>
                                            <button type="button" class="btn btn-danger btn-sm mark-credit-btn"
                                                    data-order-id="<?= $row['id'] ?>"
                                                    data-total="<?= $row['total_amount'] ?>">
                                                Mark Credit
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <h5>No current orders</h5>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Success Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
    <div id="successToast" class="toast align-items-center text-white bg-success border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body">
                <strong>✓ Order successfully marked as Credit/Udhar!</strong>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- Mark Credit Modal -->
<div class="modal fade" id="markCreditModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Mark Order as Credit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="creditOrderId">
                <div class="mb-3">
                    <label class="form-label">Customer</label>
                    <div class="position-relative">
                        <input type="text" class="form-control" id="creditCustomerSearch" placeholder="Search by name or phone..." autocomplete="off">
                        <div id="creditCustomerDropdown" class="dropdown-menu w-100"></div>
                    </div>
                    <div id="selectedCreditCustomer" class="mt-2" style="display:none;">
                        Selected: <strong id="selectedCreditCustomerName"></strong>
                        <input type="hidden" id="creditCustomerId">
                    </div>
                </div>

                <div id="newCustomerFields" style="display:none;">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone (optional)</label>
                            <input type="text" class="form-control" id="newCustomerPhone">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Address (optional)</label>
                            <input type="text" class="form-control" id="newCustomerAddress">
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Previous Due: <span id="creditPreviousDue">Rs. 0.00</span></label>
                </div>

                <div class="mb-3">
                    <label class="form-label">Discount (Rs.)</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="creditDiscount" value="0.00">
                </div>

                <div class="mb-3">
                    <label class="form-label">Paid Today (Rs.)</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="creditPaidToday" value="0.00">
                </div>

                <div class="mb-3">
                    <label class="form-label">Payment Method (if paid today)</label>
                    <select class="form-select" id="creditPaymentMethod">
                        <option value="cash">Cash</option>
                        <option value="online">Online</option>
                    </select>
                </div>

                <hr>
                <h6>Bill Preview</h6>
                <table class="table table-sm">
                    <tbody id="previewChargesBody"></tbody>
                    <tr id="discountPreviewRow" style="display:none;">
                        <td class="text-start">Discount</td>
                        <td class="text-end">- Rs. <span id="previewDiscount">0.00</span></td>
                    </tr>
                    <tr>
                        <td class="text-start">Taxable Amount</td>
                        <td class="text-end">Rs. <span id="previewTaxable">0.00</span></td>
                    </tr>
                    <tr id="vatRow" style="display:none;">
                        <td class="text-start">VAT (13%)</td>
                        <td class="text-end">Rs. <span id="previewVat">0.00</span></td>
                    </tr>
                    <tr class="table-primary">
                        <td class="text-start fw-bold">Net Total</td>
                        <td class="text-end fw-bold">Rs. <span id="previewNetTotal">0.00</span></td>
                    </tr>
                    <tr class="table-info">
                        <td class="text-start fw-bold">Total Amount Due (incl. previous)</td>
                        <td class="text-end fw-bold">Rs. <span id="totalAmountDue">0.00</span></td>
                    </tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmMarkCredit">Continue</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmCreditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Credit Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Customer: <strong id="confirmCustomerName"></strong></p>
                <p>Net Total: <strong id="confirmNetTotal"></strong></p>
                <p>Paid Today: <strong id="confirmPaidToday">0.00</strong></p>
                <p>Remaining Due will be updated accordingly.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="finalConfirmCredit">Confirm Mark as Credit</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Global data
let orderData = { subtotal: 0, charges: [], vatPercent: 0 };

document.querySelectorAll('.mark-credit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const orderId = this.dataset.orderId;
        const subtotal = parseFloat(this.dataset.total) || 0;

        document.getElementById('creditOrderId').value = orderId;

        // Reset form
        document.getElementById('creditDiscount').value = '0.00';
        document.getElementById('creditPaidToday').value = '0.00';
        document.getElementById('creditCustomerSearch').value = '';
        document.getElementById('creditCustomerId').value = '';
        document.getElementById('selectedCreditCustomer').style.display = 'none';
        document.getElementById('newCustomerFields').style.display = 'none';
        document.getElementById('creditCustomerDropdown').innerHTML = '';
        document.getElementById('creditPreviousDue').textContent = 'Rs. 0.00';

        // Fetch charges & VAT
        const formData = new FormData();
        formData.append('ajax_get_charges', '1');

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            orderData = {
                subtotal: subtotal,
                charges: data.charges || [],
                vatPercent: data.vat_percent || 0
            };
            calculatePreview();
        });

        new bootstrap.Modal(document.getElementById('markCreditModal')).show();
    });
});

// Calculation
function calculatePreview() {
    const subtotal = orderData.subtotal;
    let discount = parseFloat(document.getElementById('creditDiscount').value) || 0;

    if (discount > subtotal) {
        discount = subtotal;
        document.getElementById('creditDiscount').value = discount.toFixed(2);
    }

    const afterDiscount = subtotal - discount;

    const chargesBody = document.getElementById('previewChargesBody');
    chargesBody.innerHTML = '';
    let totalCharges = 0;

    orderData.charges.forEach(c => {
        const amt = afterDiscount * (c.percent / 100);
        if (amt > 0) {
            totalCharges += amt;
            const row = document.createElement('tr');
            row.innerHTML = `<td class="text-start">${c.name} (${c.percent}%)</td><td class="text-end">${amt.toFixed(2)}</td>`;
            chargesBody.appendChild(row);
        }
    });

    const taxable = afterDiscount + totalCharges;
    const vat = orderData.vatPercent > 0 ? taxable * (orderData.vatPercent / 100) : 0;
    const netTotal = taxable + vat;

    const previousDue = parseFloat(document.getElementById('creditPreviousDue').textContent.replace('Rs. ', '')) || 0;
    const totalAmountDue = previousDue + netTotal;

    // Update UI
    document.getElementById('previewDiscount').textContent = discount.toFixed(2);
    document.getElementById('discountPreviewRow').style.display = discount > 0 ? '' : 'none';

    document.getElementById('previewTaxable').textContent = taxable.toFixed(2);
    document.getElementById('previewVat').textContent = vat.toFixed(2);
    document.getElementById('vatRow').style.display = vat > 0 ? '' : 'none';

    document.getElementById('previewNetTotal').textContent = netTotal.toFixed(2);
    document.getElementById('totalAmountDue').textContent = totalAmountDue.toFixed(2);
}

// Live updates
document.getElementById('creditDiscount').addEventListener('input', calculatePreview);

// Customer search
const creditSearch = document.getElementById('creditCustomerSearch');
const creditDropdown = document.getElementById('creditCustomerDropdown');

creditSearch.addEventListener('input', function() {
    const query = this.value.trim();
    creditDropdown.innerHTML = '';
    creditDropdown.style.display = 'none';

    document.getElementById('newCustomerFields').style.display = 'none';
    document.getElementById('selectedCreditCustomer').style.display = 'none';
    document.getElementById('creditCustomerId').value = '';

    document.getElementById('creditPreviousDue').textContent = 'Rs. 0.00';
    calculatePreview();

    if (query.length < 2) return;

    const formData = new FormData();
    formData.append('ajax_search_customer', '1');
    formData.append('query', query);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        creditDropdown.innerHTML = '';

        const addNew = document.createElement('div');
        addNew.className = 'dropdown-item fw-bold text-primary';
        addNew.innerHTML = `+ Add New Customer: <strong>${query}</strong>`;
        addNew.onclick = () => {
            creditSearch.value = query;
            document.getElementById('creditCustomerId').value = '';
            document.getElementById('newCustomerFields').style.display = 'block';
            document.getElementById('selectedCreditCustomer').style.display = 'none';
            document.getElementById('creditPreviousDue').textContent = 'Rs. 0.00';
            creditDropdown.style.display = 'none';
            calculatePreview();
        };
        creditDropdown.appendChild(addNew);

        const sep = document.createElement('hr');
        sep.className = 'dropdown-divider';
        creditDropdown.appendChild(sep);

        if (data.length === 0) {
            const noResult = document.createElement('div');
            noResult.className = 'dropdown-item text-muted';
            noResult.textContent = 'No customer found';
            creditDropdown.appendChild(noResult);
        } else {
            data.forEach(cust => {
                const item = document.createElement('div');
                item.className = 'dropdown-item';
                item.innerHTML = cust.label;
                item.onclick = () => {
                    document.getElementById('creditCustomerId').value = cust.id;
                    document.getElementById('selectedCreditCustomerName').textContent = cust.name + ' (' + cust.phone + ')';
                    document.getElementById('creditPreviousDue').textContent = 'Rs. ' + cust.due;
                    document.getElementById('selectedCreditCustomer').style.display = 'block';
                    document.getElementById('newCustomerFields').style.display = 'none';
                    creditSearch.value = cust.name;
                    creditDropdown.style.display = 'none';
                    calculatePreview();
                };
                creditDropdown.appendChild(item);
            });
        }

        creditDropdown.style.display = 'block';
    });
});

// Confirmation modal
document.getElementById('confirmMarkCredit').addEventListener('click', function() {
    const customerId = document.getElementById('creditCustomerId').value;
    const name = document.getElementById('creditCustomerSearch').value.trim();

    if (!customerId && !name) {
        alert('Please select or enter a customer name');
        return;
    }

    let displayName = name || document.getElementById('selectedCreditCustomerName').textContent;
    if (!customerId) displayName += ' (New)';

    document.getElementById('confirmCustomerName').textContent = displayName;
    document.getElementById('confirmNetTotal').textContent = document.getElementById('totalAmountDue').textContent;
    document.getElementById('confirmPaidToday').textContent = document.getElementById('creditPaidToday').value || '0.00';

    bootstrap.Modal.getInstance(document.getElementById('markCreditModal')).hide();
    new bootstrap.Modal(document.getElementById('confirmCreditModal')).show();
});

// Final confirm
document.getElementById('finalConfirmCredit').addEventListener('click', function() {
    let customerId = document.getElementById('creditCustomerId').value;
    const name = document.getElementById('creditCustomerSearch').value.trim();
    const phone = document.getElementById('newCustomerPhone').value.trim();
    const address = document.getElementById('newCustomerAddress').value.trim();
    const discount = parseFloat(document.getElementById('creditDiscount').value) || 0;
    const paidToday = parseFloat(document.getElementById('creditPaidToday').value) || 0;
    const paymentMethod = document.getElementById('creditPaymentMethod').value;
    const orderId = document.getElementById('creditOrderId').value;
    const netTotal = parseFloat(document.getElementById('previewNetTotal').textContent);

    if (!customerId && !name) {
        alert('Customer name is required');
        return;
    }

    if (!customerId && name) {
        const formData = new FormData();
        formData.append('name', name);
        if (phone) formData.append('phone', phone);
        if (address) formData.append('address', address);

        fetch('create_customer.php', {  // Change to '../Common/create_customer.php' if moved to Common
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                customerId = data.customer_id;
                doMarkCredit(customerId, netTotal, discount, paidToday, paymentMethod, orderId);
            } else {
                alert('Failed to create customer: ' + (data.error || 'Unknown'));
            }
        });
    } else {
        doMarkCredit(customerId, netTotal, discount, paidToday, paymentMethod, orderId);
    }
});

function doMarkCredit(customerId, netTotal, discount, paidToday, paymentMethod, orderId) {
    const formData = new FormData();
    formData.append('order_id', orderId);
    formData.append('customer_id', customerId);
    formData.append('paid_today', paidToday);
    formData.append('net_total', netTotal);
    formData.append('discount', discount);
    formData.append('payment_method', paymentMethod);

    fetch('mark_credit.php', {  // Change to '../Common/mark_credit.php' if moved to Common
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const toast = new bootstrap.Toast(document.getElementById('successToast'));
            toast.show();
            setTimeout(() => location.reload(), 3000);
        } else {
            alert('Error: ' + (data.error || 'Failed'));
        }
    });
}
</script>
</body>
</html>