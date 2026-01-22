<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';
$conn->query("SET time_zone = '+05:45'");

// Must be logged in as admin
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

$restaurant_id = (int)$_SESSION['restaurant_id'];
$user_id       = (int)$_SESSION['user_id'];

// Load restaurant name
$restaurant_name = 'Restaurant';
$stmt = $conn->prepare("SELECT name FROM restaurants WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $restaurant_name = $row['name'];
}
$stmt->close();

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

/* --------------------- Default Prefill --------------------- */
$today_bs = substr(nepali_date_time(), 0, 10);
$nepali_datetime_full = nepali_date_time();
$inserted_at_bs = $nepali_datetime_full;

$prefill = [
    'purchased_from'   => '',
    'purchase_date_bs' => $today_bs,
    'fiscal_year'      => get_fiscal_year($today_bs),
    'items'            => [],
    'grand_total'      => 0
];

if (isset($_SESSION['prefill_general'])) {
    $prefill = $_SESSION['prefill_general'];
    unset($_SESSION['prefill_general']);
}

/* --------------------- POST Handling --------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->autocommit(false);

    try {
        $purchased_from   = trim($_POST['purchased_from'] ?? '');
        $purchase_date_bs = trim($_POST['purchase_date_bs'] ?? '');

        if (!$purchased_from) throw new Exception("Purchased From is required.");
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchase_date_bs)) {
            throw new Exception("Invalid BS date format.");
        }

        $fiscal_year = get_fiscal_year($purchase_date_bs);

        $names = $_POST['item_name'] ?? [];
        $qtys  = $_POST['quantity'] ?? [];
        $rates = $_POST['rate'] ?? [];
        $units = $_POST['unit'] ?? [];

        if (empty($names)) throw new Exception("At least one item is required.");

        $items_data = [];
        $grand_total = 0;

        foreach ($names as $i => $name) {
            $name = trim($name);
            $qty  = floatval($qtys[$i] ?? 0);
            $rate = floatval($rates[$i] ?? 0);
            $unit = trim($units[$i] ?? '');
            $total = $qty * $rate;

            if (!$name || $qty <= 0 || $rate <= 0 || !$unit) {
                throw new Exception("Item " . ($i + 1) . ": All fields required and values must be > 0.");
            }

            $items_data[] = [
                'item_name' => $name,
                'quantity'  => $qty,
                'rate'      => $rate,
                'unit'      => $unit,
                'total'     => $total
            ];
            $grand_total += $total;
        }

        $items_json = json_encode($items_data, JSON_UNESCAPED_UNICODE);
        if ($items_json === false) throw new Exception("Failed to encode items.");

        $stmt = $conn->prepare("
            INSERT INTO general_bill 
                (restaurant_id, purchased_from, purchase_date, fiscal_year, items_json, inserted_by, inserted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issssis", $restaurant_id, $purchased_from, $purchase_date_bs, $fiscal_year, $items_json, $user_id, $inserted_at_bs);
        if (!$stmt->execute()) throw new Exception("Save failed: " . $stmt->error);

        $stmt->close();
        $conn->commit();

        // Reset form on success
        $prefill = [
            'purchased_from'   => '',
            'purchase_date_bs' => $today_bs,
            'fiscal_year'      => get_fiscal_year($today_bs),
            'items'            => [],
            'grand_total'      => 0
        ];

        $_SESSION['message'] = "
        <div class='alert alert-success d-flex align-items-start'>
            <i class='bi bi-check-circle-fill me-3 fs-4'></i>
            <div><h6 class='alert-heading fw-bold'>Success!</h6>
            General bill saved successfully.<br>
            <small>Date: <strong>$purchase_date_bs</strong> | Fiscal Year: <strong>$fiscal_year</strong></small></div>
        </div>";

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "
        <div class='alert alert-danger d-flex align-items-start'>
            <i class='bi bi-x-circle-fill me-3 fs-4'></i>
            <div>" . htmlspecialchars($e->getMessage()) . "</div>
        </div>";

        // Preserve entered data on error
        $prefill = [
            'purchased_from'   => $purchased_from ?? '',
            'purchase_date_bs' => $purchase_date_bs ?? $today_bs,
            'fiscal_year'      => get_fiscal_year($purchase_date_bs ?? $today_bs),
            'items'            => [],
            'grand_total'      => 0
        ];
        foreach ($names as $i => $n) {
            $total = floatval($qtys[$i] ?? 0) * floatval($rates[$i] ?? 0);
            $prefill['items'][] = [
                'item_name' => trim($n ?? ''),
                'quantity'  => floatval($qtys[$i] ?? 0),
                'rate'      => floatval($rates[$i] ?? 0),
                'unit'      => trim($units[$i] ?? ''),
                'total'     => $total
            ];
            $prefill['grand_total'] += $total;
        }
        $_SESSION['prefill_general'] = $prefill;
    }

    header("Location: general_purchase.php");
    exit;
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>General Bill Entry - <?= htmlspecialchars($restaurant_name) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../Common/admin.css" rel="stylesheet">
    <style>
        .alert { transition: opacity 0.8s; }
        #fy_display { font-weight: bold; color: #0d6efd; }
    </style>
</head>
<body class="menu-body">

<!-- Back to Dashboard Button -->
<div class="container mt-4">
    <div class="back-btn">
        <a href="dashboard.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
        </a>
    </div>
</div>

<div class="container mt-4">
    <h3 class="text-center text-primary mb-4">General Bill / Purchase Entry</h3>

    <?php if ($message): ?>
        <div id="msgBox"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="row mb-4">
            <div class="col-md-6">
                <label class="form-label">Purchased From <span class="text-danger">*</span></label>
                <input type="text" name="purchased_from" class="form-control" 
                       value="<?= htmlspecialchars($prefill['purchased_from']) ?>" required autofocus>
            </div>
            <div class="col-md-6">
                <label class="form-label">Purchase Date (BS) <span class="text-danger">*</span></label>
                <input type="text" name="purchase_date_bs" id="purchase_date_bs" class="form-control" 
                       placeholder="2081-08-08" value="<?= htmlspecialchars($prefill['purchase_date_bs']) ?>" required>
                <small class="text-muted d-block mt-1">
                    Fiscal Year: <strong id="fy_display"><?= htmlspecialchars($prefill['fiscal_year']) ?></strong>
                </small>
            </div>
        </div>

        <hr>
        <h5 class="mb-3">Items</h5>

        <div class="table-responsive">
            <table class="table table-bordered align-middle" id="items_table">
                <thead class="table-light">
                    <tr>
                        <th>Item Name</th>
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
                        <td><input type="number" name="quantity[]" class="form-control qty" step="0.001" min="0.001" value="1" required></td>
                        <td><input type="number" name="rate[]" class="form-control rate" step="0.01" min="0.01" required></td>
                        <td><input type="text" name="unit[]" class="form-control" value="kg" required></td>
                        <td><input type="number" class="form-control total" readonly></td>
                        <td class="text-center"><button type="button" class="btn btn-danger btn-sm remove_row">Remove</button></td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($prefill['items'] as $it): ?>
                        <tr>
                            <td><input type="text" name="item_name[]" class="form-control" value="<?= htmlspecialchars($it['item_name']) ?>" required></td>
                            <td><input type="number" name="quantity[]" class="form-control qty" step="0.001" value="<?= $it['quantity'] ?>" required></td>
                            <td><input type="number" name="rate[]" class="form-control rate" step="0.01" value="<?= $it['rate'] ?>" required></td>
                            <td><input type="text" name="unit[]" class="form-control" value="<?= htmlspecialchars($it['unit']) ?>" required></td>
                            <td><input type="number" class="form-control total" value="<?= number_format($it['total'], 2) ?>" readonly></td>
                            <td class="text-center"><button type="button" class="btn btn-danger btn-sm remove_row">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="text-center my-3">
            <button type="button" id="add_item" class="btn btn-outline-primary">Add Item</button>
        </div>

        <div class="text-end mb-4">
            <h4 class="d-inline">Grand Total: </h4>
            <h3 class="d-inline text-success fw-bold" id="grand_total">
                <?= number_format($prefill['grand_total'], 2) ?>
            </h3>
            <span class="text-muted fs-5"> NPR</span>
        </div>

        <div class="text-center">
            <button type="submit" class="btn btn-success btn-lg px-5">Save Bill</button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tbody = document.querySelector('#items_table tbody');
    const dateInput = document.getElementById('purchase_date_bs');
    const fyDisplay = document.getElementById('fy_display');
    const grandTotal = document.getElementById('grand_total');

    function updateFiscalYear() {
        const d = dateInput.value.trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return;
        const [y, m] = d.split('-').map(Number);
        const fy = (m >= 1 && m <= 3) ? `${y-1}/${String(y).slice(-2)}` : `${y}/${y+1}`;
        fyDisplay.textContent = fy;
    }

    function calcRow(row) {
        const qty = parseFloat(row.querySelector('.qty').value) || 0;
        const rate = parseFloat(row.querySelector('.rate').value) || 0;
        row.querySelector('.total').value = (qty * rate).toFixed(2);
        calcGrandTotal();
    }

    function calcGrandTotal() {
        let sum = 0;
        document.querySelectorAll('.total').forEach(t => sum += parseFloat(t.value) || 0);
        grandTotal.textContent = sum.toFixed(2);
    }

    tbody.querySelectorAll('tr').forEach(calcRow);
    updateFiscalYear();

    dateInput.addEventListener('input', updateFiscalYear);
    tbody.addEventListener('input', e => {
        if (e.target.matches('.qty, .rate')) calcRow(e.target.closest('tr'));
    });

    document.getElementById('add_item').addEventListener('click', () => {
        const row = tbody.insertRow();
        row.innerHTML = `
            <td><input type="text" name="item_name[]" class="form-control" required></td>
            <td><input type="number" name="quantity[]" class="form-control qty" step="0.001" min="0.001" value="1" required></td>
            <td><input type="number" name="rate[]" class="form-control rate" step="0.01" min="0.01" required></td>
            <td><input type="text" name="unit[]" class="form-control" value="kg" required></td>
            <td><input type="number" class="form-control total" readonly></td>
            <td class="text-center"><button type="button" class="btn btn-danger btn-sm remove_row">Remove</button></td>
        `;
        calcGrandTotal();
    });

    tbody.addEventListener('click', e => {
        if (e.target.classList.contains('remove_row') && tbody.rows.length > 1) {
            e.target.closest('tr').remove();
            calcGrandTotal();
        }
    });

    const msgBox = document.getElementById('msgBox');
    if (msgBox) {
        setTimeout(() => {
            msgBox.style.opacity = '0';
            setTimeout(() => msgBox.remove(), 1000);
        }, 10000);
    }
});
</script>

<?php include '../Common/footer.php'; ?>
</body>
</html>