<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

// Set MySQL to Nepal time for AD datetime in stock_inventory
$conn->query("SET time_zone = '+05:45'");

// SuperAdmin must have a current restaurant selected
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin' || empty($_SESSION['current_restaurant_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

$restaurant_id = (int)$_SESSION['current_restaurant_id'];
$added_by = (int)$_SESSION['user_id'];
$restaurant_name = $_SESSION['current_restaurant_name'] ?? 'Restaurant';

$message = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

/* -------------------------------------------------------------
   Default values (same as Admin)
   ------------------------------------------------------------- */
$prefill = [
    'company_name'        => '',
    'bill_no'             => '',
    'purchaser_vat_no'    => '',
    'address'             => '',
    'transaction_date_bs' => '',
    'discount'            => 0,
    'vat_option'          => 0,
    'items'               => [],
    'grand_total'         => 0,
    'net_total'           => 0,
    'fiscal_year'         => '',
    'added_by'            => $added_by
];

$current_bs_datetime = nepali_date_time();
$today_bs = substr($current_bs_datetime, 0, 10);
$prefill['transaction_date_bs'] = $today_bs;
$prefill['fiscal_year']         = get_fiscal_year($today_bs);

if (isset($_SESSION['prefill'])) {
    $prefill = $_SESSION['prefill'];
    unset($_SESSION['prefill']);
}

/* -------------------------------------------------------------
   POST handling
   ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();

    try {
        // Use restaurant_id from session (SuperAdmin chooses restaurant elsewhere)
        $company_name        = trim($_POST['company_name'] ?? '');
        $bill_no             = trim($_POST['bill_no'] ?? '');
        $purchaser_vat_no    = trim($_POST['purchaser_vat_no'] ?? '');
        $address             = trim($_POST['address'] ?? '');
        $transaction_date_bs = trim($_POST['transaction_date_bs'] ?? '');
        $discount            = floatval($_POST['discount'] ?? 0);
        $vat_percent         = ($_POST['vat_option'] ?? '') === '13' ? 13 : 0;

        if (!$company_name || !$bill_no || !$transaction_date_bs) {
            throw new Exception("Company name, Bill number and Transaction date are required.");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transaction_date_bs)) {
            throw new Exception("Invalid date format. Use YYYY-MM-DD.");
        }
        if ($transaction_date_bs > $today_bs) {
            throw new Exception("Transaction date cannot be in the future.");
        }

        $fiscal_year = get_fiscal_year($transaction_date_bs);

        /* ----- duplicate bill check ----- */
        $chk = $conn->prepare(
            "SELECT 1 FROM purchase 
             WHERE restaurant_id = ? 
               AND company_name = ? 
               AND bill_no = ? 
               AND fiscal_year = ?"
        );
        if (!$chk) throw new Exception("DB error (duplicate check): " . $conn->error);
        $chk->bind_param('isss', $restaurant_id, $company_name, $bill_no, $fiscal_year);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows) {
            throw new Exception("DUPLICATE_BILL|$bill_no|$company_name|$fiscal_year");
        }
        $chk->close();

        /* ----- items (with hs_code per item) ----- */
        $item_names = $_POST['item_name'] ?? [];
        $hs_codes   = $_POST['hs_code_item'] ?? [];
        $qtys       = $_POST['quantity'] ?? [];
        $rates      = $_POST['rate'] ?? [];
        $units      = $_POST['unit'] ?? [];

        if (!is_array($item_names) || count($item_names) === 0) {
            throw new Exception("At least one item is required.");
        }

        $items_data   = [];
        $total_amount = 0;
        foreach ($item_names as $i => $name) {
            $name     = trim($name);
            $hs_code  = trim($hs_codes[$i] ?? '');
            $qty      = floatval($qtys[$i] ?? 0);
            $rate     = floatval($rates[$i] ?? 0);
            $unit     = trim($units[$i] ?? '');
            $line     = $qty * $rate;

            if (!$name || $qty <= 0 || $rate <= 0 || !$unit) {
                throw new Exception("Item " . ($i + 1) . ": All fields required and >0.");
            }

            $items_data[] = [
                'name'     => $name,
                'hs_code'  => $hs_code,
                'quantity' => $qty,
                'rate'     => $rate,
                'unit'     => $unit,
                'total'    => $line
            ];
            $total_amount += $line;
        }

        $taxable   = $total_amount - $discount;
        if ($taxable < 0) $taxable = 0;
        $net_total = $vat_percent == 13 ? $taxable * 1.13 : $taxable;

        /* ----- save purchase ----- */
        $items_json = json_encode($items_data, JSON_UNESCAPED_UNICODE);
        $created_at_bs = nepali_date_time();  // BS datetime

        $ins = $conn->prepare(
            "INSERT INTO purchase 
                (restaurant_id, bill_no, company_name, vat_no, address, transaction_date,
                 fiscal_year, added_by, created_at, items_json, total_amount, discount,
                 taxable_amount, vat_percent, net_total)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        if (!$ins) throw new Exception("Prepare failed: " . $conn->error);

        $ins->bind_param(
            'iisssssisssdddi',  // 15 types — matches your Admin file
            $restaurant_id, 
            $bill_no,
            $company_name,
            $purchaser_vat_no,
            $address,
            $transaction_date_bs,
            $fiscal_year,
            $added_by,
            $created_at_bs,
            $items_json,
            $total_amount,
            $discount,
            $taxable,
            $vat_percent,
            $net_total
        );

        if (!$ins->execute()) {
            throw new Exception("Failed to save: " . $ins->error);
        }
        $ins->close();

        /* ----- stock_inventory (BS datetime) ----- */
        $current_bs_datetime = nepali_date_time();

        $stock_sql = "
            INSERT INTO stock_inventory
                (restaurant_id, stock_name, quantity, unit, created_at, updated_at)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                quantity = quantity + VALUES(quantity),
                unit = VALUES(unit),
                updated_at = VALUES(updated_at)
        ";

        $st = $conn->prepare($stock_sql);
        if (!$st) {
            throw new Exception('Stock prepare failed: ' . $conn->error);
        }

        foreach ($items_data as $it) {
            $st->bind_param(
                'isdsss',
                $restaurant_id,
                $it['name'],
                $it['quantity'],
                $it['unit'],
                $current_bs_datetime, // created_at (BS)
                $current_bs_datetime  // updated_at (BS)
            );

            if (!$st->execute()) {
                throw new Exception("Stock update error for '{$it['name']}': " . $st->error);
            }
        }

        $st->close();

        $conn->commit();

        $_SESSION['message'] = "
        <div class='alert alert-success d-flex align-items-start' role='alert'>
            <i class='bi bi-check-circle-fill me-3 fs-4'></i>
            <div>
                <h6 class='alert-heading fw-bold'>Purchase Saved!</h6>
                <p class='mb-0'>Fiscal Year: <strong>$fiscal_year</strong> | Transaction Date: <strong>$transaction_date_bs</strong> | Created At: <strong>$created_at_bs</strong></p>
            </div>
        </div>";

        header('Location: stock_management.php');
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $raw_msg = $e->getMessage();

        if (str_starts_with($raw_msg, 'DUPLICATE_BILL|')) {
            [, $bill_no, $company_name, $fiscal_year] = explode('|', $raw_msg, 4);
            $_SESSION['message'] = "
            <div class='alert alert-warning d-flex align-items-start' role='alert'>
                <i class='bi bi-exclamation-triangle-fill me-3 fs-4'></i>
                <div>
                    <h6 class='alert-heading fw-bold'>Duplicate Bill Number</h6>
                    <hr class='my-2'>
                    <p class='mb-1'><strong>Bill No:</strong> " . htmlspecialchars($bill_no) . "</p>
                    <p class='mb-1'><strong>Company:</strong> " . htmlspecialchars($company_name) . "</p>
                    <p class='mb-1'><strong>Fiscal Year:</strong> <span class='badge bg-danger'>$fiscal_year</span></p>
                    <p class='mb-0 mt-3'>
                        Already used in this fiscal year.<br>
                        <strong class='text-success'>Allowed:</strong> Same bill in a <u>different fiscal year</u>.
                    </p>
                </div>
            </div>";
        } else {
            $msg = htmlspecialchars($raw_msg, ENT_QUOTES, 'UTF-8');
            $_SESSION['message'] = "
            <div class='alert alert-danger d-flex align-items-start' role='alert'>
                <i class='bi bi-x-circle-fill me-3 fs-4'></i>
                <div>$msg</div>
            </div>";
        }

        // Preserve form data
        $prefill = [
            'company_name'        => $company_name ?? '',
            'bill_no'             => $bill_no ?? '',
            'purchaser_vat_no'    => $purchaser_vat_no ?? '',
            'address'             => $address ?? '',
            'transaction_date_bs' => $transaction_date_bs ?? $today_bs,
            'discount'            => $discount,
            'vat_option'          => $vat_percent,
            'items'               => [],
            'grand_total'         => 0,
            'net_total'           => 0,
            'fiscal_year'         => $fiscal_year ?? get_fiscal_year($transaction_date_bs ?? $today_bs),
            'added_by'            => $added_by
        ];

        $names = $_POST['item_name'] ?? [];
        $hsc   = $_POST['hs_code_item'] ?? [];
        $qtys  = $_POST['quantity'] ?? [];
        $rates = $_POST['rate'] ?? [];
        $units = $_POST['unit'] ?? [];

        $total_amount = 0;
        foreach ($names as $i => $n) {
            $name = trim($n ?? '');
            $hs   = trim($hsc[$i] ?? '');
            $qty  = floatval($qtys[$i] ?? 0);
            $rate = floatval($rates[$i] ?? 0);
            $unit = trim($units[$i] ?? '');
            $line = $qty * $rate;
            $total_amount += $line;

            $prefill['items'][] = [
                'name'     => $name,
                'hs_code'  => $hs,
                'quantity' => $qty,
                'rate'     => $rate,
                'unit'     => $unit,
                'total'    => $line
            ];
        }

        $taxable = $total_amount - $discount;
        if ($taxable < 0) $taxable = 0;
        $prefill['grand_total'] = $total_amount;
        $prefill['net_total']   = $vat_percent == 13 ? $taxable * 1.13 : $taxable;

        $_SESSION['prefill'] = $prefill;

        header('Location: stock_management.php');
        exit;
    }
}
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Purchase Entry - SuperAdmin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="../Common/admin.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<style>
.alert { transition: opacity 0.5s; }
</style>
</head>
<body class="menu-body">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h3 class="text-center text-success mb-3">
        <?= htmlspecialchars($restaurant_name) ?> - Purchase Entry (SuperAdmin)
    </h3>

    <div class="text-center mb-3">
        <a href="view_branch.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>


    <?php if ($message): ?>
        <div id="msgBox"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST" id="purchaseForm" class="border p-3 rounded shadow-sm bg-white">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Company Name <span class="text-danger">*</span></label>
                <input type="text" name="company_name" class="form-control"
                       value="<?=htmlspecialchars($prefill['company_name'])?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Bill Number <span class="text-danger">*</span></label>
                <input type="text" name="bill_no" class="form-control"
                       value="<?=htmlspecialchars($prefill['bill_no'])?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">VAT No</label>
                <input type="text" name="purchaser_vat_no" class="form-control"
                       value="<?=htmlspecialchars($prefill['purchaser_vat_no'])?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-control"
                       value="<?=htmlspecialchars($prefill['address'])?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Transaction Date (BS) <span class="text-danger">*</span></label>
                <input type="text" name="transaction_date_bs" class="form-control"
                       placeholder="YYYY-MM-DD" value="<?=htmlspecialchars($prefill['transaction_date_bs'])?>" required>
                <small class="text-muted">Fiscal Year: <strong><?=htmlspecialchars($prefill['fiscal_year'])?></strong></small>
            </div>
        </div>

        <hr>
        <h5>Items</h5>
        <table class="table table-bordered" id="items_table">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>HS Code</th>
                    <th>Quantity</th>
                    <th>Rate</th>
                    <th>Unit</th>
                    <th>Total</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($prefill['items'])): ?>
                    <tr>
                        <td><input type="text" name="item_name[]" class="form-control" required></td>
                        <td><input type="text" name="hs_code_item[]" class="form-control" placeholder="e.g. 1006.30"></td>
                        <td><input type="number" name="quantity[]" class="form-control qty" step="0.01" min="0" value="0" required></td>
                        <td><input type="number" name="rate[]" class="form-control rate" step="0.01" min="0" value="0" required></td>
                    <td><input type="text" name="unit[]" class="form-control" placeholder="kg, pcs" required></td>
                    <td><input type="number" class="form-control total" value="0" readonly></td>
                    <td><button type="button" class="btn btn-danger btn-sm remove_row">Remove</button></td>
                </tr>
                <?php else: ?>
                    <?php foreach ($prefill['items'] as $it): ?>
                        <tr>
                            <td><input type="text" name="item_name[]" class="form-control"
                                       value="<?=htmlspecialchars($it['name'])?>" required></td>
                            <td><input type="text" name="hs_code_item[]" class="form-control"
                                       value="<?=htmlspecialchars($it['hs_code'] ?? '')?>" placeholder="e.g. 1006.30"></td>
                            <td><input type="number" name="quantity[]" class="form-control qty" step="0.01" min="0"
                                       value="<?=number_format($it['quantity'],2,'.','')?>" required></td>
                            <td><input type="number" name="rate[]" class="form-control rate" step="0.01" min="0"
                                       value="<?=number_format($it['rate'],2,'.','')?>" required></td>
                            <td><input type="text" name="unit[]" class="form-control"
                                       value="<?=htmlspecialchars($it['unit'])?>" required></td>
                            <td><input type="number" class="form-control total"
                                       value="<?=number_format($it['total'],2,'.','')?>" readonly></td>
                            <td><button type="button" class="btn btn-danger btn-sm remove_row">Remove</button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="text-center mb-3">
            <button type="button" id="add_item" class="btn btn-primary btn-sm">Add Item</button>
        </div>

        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Discount</label>
                <input type="number" name="discount" id="discount" class="form-control" step="0.01" min="0"
                       value="<?=number_format($prefill['discount'],2,'.','')?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">VAT Option</label>
                <select name="vat_option" id="vat_option" class="form-select">
                    <option value="0" <?= $prefill['vat_option']==0?'selected':'' ?>>No VAT</option>
                    <option value="13" <?= $prefill['vat_option']==13?'selected':'' ?>>13% VAT</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Grand Total</label>
                <input type="number" id="grand_total" class="form-control"
                       value="<?=number_format($prefill['grand_total'],2,'.','')?>" readonly>
            </div>
            <div class="col-md-3">
                <label class="form-label">Net Total</label>
                <input type="number" id="net_total" class="form-control"
                       value="<?=number_format($prefill['net_total'],2,'.','')?>" readonly>
            </div>
        </div>

        <div class="text-center mt-3">
            <button type="submit" class="btn btn-success px-4">Save Entry</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tableBody = document.querySelector('#items_table tbody');
    const addBtn = document.getElementById('add_item');
    const discountInp = document.getElementById('discount');
    const vatSel = document.getElementById('vat_option');
    const grandInp = document.getElementById('grand_total');
    const netInp = document.getElementById('net_total');
    const dateInp = document.querySelector('[name="transaction_date_bs"]');
    const fyDisplay = dateInp.parentElement.querySelector('small');

    function calcRow(row) {
        const qty = parseFloat(row.querySelector('.qty').value) || 0;
        const rate = parseFloat(row.querySelector('.rate').value) || 0;
        row.querySelector('.total').value = (qty * rate).toFixed(2);
        calcTotals();
    }

    function calcTotals() {
        let sum = 0;
        document.querySelectorAll('.total').forEach(t => sum += parseFloat(t.value) || 0);
        const disc = parseFloat(discountInp.value) || 0;
        const vat = vatSel.value === '13' ? 0.13 : 0;
        const after = Math.max(sum - disc, 0);
        const net = after * (1 + vat);
        grandInp.value = sum.toFixed(2);
        netInp.value = net.toFixed(2);
    }

    function updateFY() {
        const d = dateInp.value.trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return;
        const [y, m] = d.split('-').map(Number);
        const fy = (m >= 1 && m <= 3) ? (y - 1) + '/' + (y + '').slice(-2) : y + '/' + (y + 1 + '').slice(-2);
        fyDisplay.innerHTML = 'Fiscal Year: <strong>' + fy + '</strong>';
    }

    tableBody.querySelectorAll('tr').forEach(calcRow);
    calcTotals();
    updateFY();

    tableBody.addEventListener('input', e => {
        if (e.target.matches('.qty, .rate')) calcRow(e.target.closest('tr'));
    });
    discountInp.addEventListener('input', calcTotals);
    vatSel.addEventListener('change', calcTotals);
    dateInp.addEventListener('input', updateFY);

    addBtn.addEventListener('click', () => {
        const row = tableBody.insertRow();
        row.innerHTML = `
            <td><input type="text" name="item_name[]" class="form-control" required></td>
            <td><input type="text" name="hs_code_item[]" class="form-control" placeholder="e.g. 1006.30"></td>
            <td><input type="number" name="quantity[]" class="form-control qty" step="0.01" min="0" value="0" required></td>
            <td><input type="number" name="rate[]" class="form-control rate" step="0.01" min="0" value="0" required></td>
            <td><input type="text" name="unit[]" class="form-control" placeholder="kg, pcs" required></td>
            <td><input type="number" class="form-control total" value="0" readonly></td>
            <td><button type="button" class="btn btn-danger btn-sm remove_row">Remove</button></td>
        `;
        calcTotals();
    });

    tableBody.addEventListener('click', e => {
        if (e.target.classList.contains('remove_row') && tableBody.rows.length > 1) {
            e.target.closest('tr').remove();
            calcTotals();
        }
    });

    // Auto-hide alerts after 20 seconds
    const msgBox = document.getElementById('msgBox');
    if (msgBox) {
        setTimeout(() => {
            const alert = msgBox.querySelector('.alert');
            if (alert) {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 600);
            }
        }, 20000);
    }
});
</script>

<?php include '../Common/footer.php'; ?>
</body>
</html>
