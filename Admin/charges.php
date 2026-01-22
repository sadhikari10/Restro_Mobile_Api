<?php
session_start();
require '../Common/connection.php'; 
require '../Common/nepali_date.php';

// SESSION CHECK (for admin only)
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['restaurant_id'])) {
    header('Location: ../common/login.php');
    exit;
}

$restaurant_id = $_SESSION['restaurant_id'];
$success_msg = '';
$error_msg = '';

// --- Helper to safely display Nepali date ---
function display_nepali_date($datetime) {
    if ($datetime && $datetime !== '0000-00-00 00:00:00') {
        return $datetime;
    } else {
        return '-';
    }
}

// Handle POST for adding or updating charge
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $charge_id = $_POST['charge_id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $percent = floatval($_POST['percent'] ?? 0);

    if ($name === '' || $percent <= 0) {
        $error_msg = "Please enter valid charge name and percent.";
    } else {
        if ($charge_id) {
            $stmt = $conn->prepare("SELECT id FROM restaurant_charges WHERE restaurant_id = ? AND name = ? AND id != ?");
            $stmt->bind_param("isi", $restaurant_id, $name, $charge_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $error_msg = "A charge with this name already exists.";
                $stmt->close();
            } else {
                $stmt->close();

                $updated_at_bs = nepali_date_time();
                $stmt = $conn->prepare("UPDATE restaurant_charges SET name = ?, percent = ?, updated_at = ? WHERE id = ? AND restaurant_id = ?");
                $stmt->bind_param("sdssi", $name, $percent, $updated_at_bs, $charge_id, $restaurant_id);

                if ($stmt->execute()) $success_msg = "Charge updated successfully!";
                else $error_msg = "Failed to update charge.";
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("SELECT id FROM restaurant_charges WHERE restaurant_id = ? AND name = ?");
            $stmt->bind_param("is", $restaurant_id, $name);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $error_msg = "A charge with this name already exists.";
                $stmt->close();
            } else {
                $stmt->close();

                $created_at_bs = nepali_date_time();
                $stmt = $conn->prepare("INSERT INTO restaurant_charges (restaurant_id, name, percent, created_at) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isds", $restaurant_id, $name, $percent, $created_at_bs);

                if ($stmt->execute()) $success_msg = "Charge added successfully!";
                else $error_msg = "Failed to add charge.";
                $stmt->close();
            }
        }
    }
}

// Handle DELETE via GET
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM restaurant_charges WHERE id = ? AND restaurant_id = ?");
    $stmt->bind_param("ii", $delete_id, $restaurant_id);
    if ($stmt->execute()) $success_msg = "Charge deleted successfully!";
    else $error_msg = "Failed to delete charge.";
    $stmt->close();
    header("Location: charges.php");
    exit;
}

// Fetch all charges
$stmt = $conn->prepare("SELECT * FROM restaurant_charges WHERE restaurant_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$charges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Charges - <?= htmlspecialchars($_SESSION['restaurant_name'] ?? 'Restaurant') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="admin.css" rel="stylesheet">
<style>
    body { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); min-height: 100vh; }
    .content { flex: 1; }
</style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light header">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <?= htmlspecialchars($_SESSION['restaurant_name'] ?? 'Restaurant Admin') ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashbaord</a>
                <a class="nav-link" href="../Common/logout.php">Logout</a>
            </div>
        </div>
    </nav>


<div class="container content py-4">
    <h3 class="text-center text-white mb-4 fw-bold">Extra Charges</h3>

    <!-- Success / Error Messages -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($success_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($error_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Add / Edit Charge Form -->
    <div class="card mb-4 p-4 shadow-sm">
        <form method="POST" action="charges.php" class="row g-3 align-items-end">
            <input type="hidden" name="charge_id" id="charge_id">
            <div class="col-md-5">
                <label class="form-label text-muted small">Charge Name</label>
                <input type="text" class="form-control" placeholder="e.g., VAT, Service Charge" name="name" id="name" required>
            </div>
            <div class="col-md-4">
                <label class="form-label text-muted small">Percent (%)</label>
                <input type="number" class="form-control" placeholder="13" step="0.01" name="percent" id="percent" required>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-success flex-fill" id="submitBtn">Add Charge</button>
                <button type="button" class="btn btn-outline-secondary flex-fill" id="clearBtn">Clear</button>
            </div>
        </form>
    </div>

    <!-- Charges Table -->
    <div class="table-responsive">
        <table class="table table-striped align-middle bg-white rounded shadow-sm">
            <thead class="table-dark">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Percent</th>
                    <th>Created At (BS)</th>
                    <th>Updated At (BS)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($charges as $index => $charge): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($charge['name']) ?></td>
                    <td><?= number_format($charge['percent'], 2) ?>%</td>
                    <td><?= display_nepali_date($charge['created_at']) ?></td>
                    <td><?= display_nepali_date($charge['updated_at']) ?></td>
                    <td>
                        <button class="btn btn-sm btn-warning editBtn"
                            data-id="<?= $charge['id'] ?>"
                            data-name="<?= htmlspecialchars($charge['name']) ?>"
                            data-percent="<?= $charge['percent'] ?>">Edit</button>
                        <a href="charges.php?delete_id=<?= $charge['id'] ?>"
                           class="btn btn-sm btn-danger"
                           onclick="return confirm('Are you sure you want to delete this charge?')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($charges)): ?>
                <tr><td colspan="6" class="text-center text-muted">No charges added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include('../Common/footer.php'); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chargeIdInput = document.getElementById('charge_id');
    const nameInput     = document.getElementById('name');
    const percentInput  = document.getElementById('percent');
    const submitBtn     = document.getElementById('submitBtn');
    const clearBtn      = document.getElementById('clearBtn');

    // Edit button
    document.querySelectorAll('.editBtn').forEach(btn => {
        btn.addEventListener('click', function() {
            chargeIdInput.value = this.dataset.id;
            nameInput.value     = this.dataset.name;
            percentInput.value  = this.dataset.percent;
            submitBtn.textContent = 'Update Charge';
            nameInput.focus();
        });
    });

    // Clear button
    clearBtn.addEventListener('click', function() {
        chargeIdInput.value = '';
        nameInput.value     = '';
        percentInput.value  = '';
        submitBtn.textContent = 'Add Charge';
        nameInput.focus();
    });

    // Auto-dismiss alerts
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            if (alert) new bootstrap.Alert(alert).close();
        }, 8000);
    });
});
</script>
</body>
</html>