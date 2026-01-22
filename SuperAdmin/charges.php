<?php
/*  SuperAdmin/charges.php  */
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

date_default_timezone_set('Asia/Kathmandu');

/* ---------- SECURITY ---------- */
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../login.php');
    exit;
}
if (empty($_SESSION['current_restaurant_id'])) {
    header('Location: index.php');
    exit;
}

$chain_id        = $_SESSION['chain_id'];
$restaurant_id   = (int)$_SESSION['current_restaurant_id'];
$restaurant_name = '';
$charges         = [];
$success_msg     = '';
$error_msg       = '';

/* ---------- FETCH RESTAURANT NAME ---------- */
$stmt = $conn->prepare("SELECT name FROM restaurants WHERE id = ? AND chain_id = ?");
$stmt->bind_param('ii', $restaurant_id, $chain_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $restaurant_name = $row['name'];
} else {
    unset($_SESSION['current_restaurant_id']);
    header('Location: index.php');
    exit;
}
$stmt->close();

/* ---------- HELPER: Display BS date ---------- */
function display_nepali_date($datetime) {
    return ($datetime && $datetime !== '0000-00-00 00:00:00') ? $datetime : '-';
}

/* ---------- HANDLE POST (Add / Update) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_charge'])) {
    $charge_id = $_POST['charge_id'] ?? '';
    $name      = trim($_POST['name'] ?? '');
    $percent   = floatval($_POST['percent'] ?? 0);

    if ($name === '' || $percent <= 0) {
        $error_msg = "Please enter valid charge name and percent.";
    } else {
        $duplicate = false;

        if ($charge_id) {
            $stmt = $conn->prepare("SELECT id FROM restaurant_charges WHERE restaurant_id = ? AND name = ? AND id != ?");
            $stmt->bind_param("isi", $restaurant_id, $name, $charge_id);
        } else {
            $stmt = $conn->prepare("SELECT id FROM restaurant_charges WHERE restaurant_id = ? AND name = ?");
            $stmt->bind_param("is", $restaurant_id, $name);
        }

        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $duplicate = true;
            $error_msg = "A charge with this name already exists.";
        }
        $stmt->close();

        if (!$duplicate) {
            if ($charge_id) {
                $updated_at_bs = nepali_date_time();
                $stmt = $conn->prepare("UPDATE restaurant_charges SET name = ?, percent = ?, updated_at = ? WHERE id = ? AND restaurant_id = ?");
                $stmt->bind_param("sdssi", $name, $percent, $updated_at_bs, $charge_id, $restaurant_id);
                $success = $stmt->execute();
                $stmt->close();
                $msg = $success ? "Charge updated successfully!" : "Failed to update.";
            } else {
                $created_at_bs = nepali_date_time();
                $stmt = $conn->prepare("INSERT INTO restaurant_charges (restaurant_id, name, percent, created_at) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isds", $restaurant_id, $name, $percent, $created_at_bs);
                $success = $stmt->execute();
                $stmt->close();
                $msg = $success ? "Charge added successfully!" : "Failed to add.";
            }

            $_SESSION['flash_success'] = $msg;
            header('Location: charges.php');
            exit;
        }
    }

    $_SESSION['form_data'] = ['charge_id' => $charge_id, 'name' => $name, 'percent' => $percent];
    $_SESSION['flash_error'] = $error_msg;
    header('Location: charges.php');
    exit;
}

/* ---------- HANDLE DELETE (AJAX) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_charge'])) {
    $delete_id = (int)$_POST['delete_id'];
    $stmt = $conn->prepare("DELETE FROM restaurant_charges WHERE id = ? AND restaurant_id = ?");
    $stmt->bind_param("ii", $delete_id, $restaurant_id);
    $success = $stmt->execute();
    $stmt->close();

    $response = ['success' => $success, 'message' => $success ? 'Charge deleted!' : 'Delete failed.'];
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

/* ---------- FLASH MESSAGES ---------- */
if (isset($_SESSION['flash_success'])) {
    $success_msg = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error_msg = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

/* ---------- RESTORE FORM DATA ON ERROR ---------- */
$form = $_SESSION['form_data'] ?? ['charge_id' => '', 'name' => '', 'percent' => ''];
unset($_SESSION['form_data']);

/* ---------- FETCH CHARGES ---------- */
$stmt = $conn->prepare("SELECT * FROM restaurant_charges WHERE restaurant_id = ? ORDER BY id DESC");
$stmt->bind_param('i', $restaurant_id);
$stmt->execute();
$charges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Manage Charges – <?= htmlspecialchars($restaurant_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../admin.css" rel="stylesheet">
    <style>
        .modal-confirm .modal-content { border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .modal-confirm .modal-header { background: #dc3545; color: white; border-top-left-radius: 12px; border-top-right-radius: 12px; }
        .modal-confirm .btn-danger { background: #dc3545; border: none; }
        .btn-clear { min-width: 100px; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h3 class="mb-4 text-center text-primary">Manage VAT & Service Charges</h3>

    <div class="text-center mb-4">
        <h5 class="text-success">
            <strong>Currently managing:</strong> <?= htmlspecialchars($restaurant_name) ?>
        </h5>
        <a href="view_branch.php" class="btn btn-sm btn-outline-secondary">Back</a>
    </div>

    <!-- Alerts -->
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

    <!-- Add / Edit Form -->
    <div class="card mb-4 p-3 shadow-sm">
        <form method="POST" class="row g-3" id="chargeForm">
            <input type="hidden" name="charge_id" id="charge_id" value="<?= htmlspecialchars($form['charge_id']) ?>">
            <div class="col-md-5">
                <input type="text" class="form-control" placeholder="Charge Name e.g., VAT" name="name" id="name" 
                       value="<?= htmlspecialchars($form['name']) ?>" required>
            </div>
            <div class="col-md-5">
                <input type="number" class="form-control" placeholder="Percent e.g., 13" step="0.01" name="percent" id="percent" 
                       value="<?= htmlspecialchars($form['percent']) ?>" required>
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-success" id="submitBtn">
                    <?= $form['charge_id'] ? 'Update' : 'Add Charge' ?>
                </button>
            </div>
        </form>
        <div class="row mt-2">
            <div class="col-md-10 offset-md-5">
                <button type="button" class="btn btn-outline-secondary btn-sm btn-clear" id="clearBtn">
                    Clear
                </button>
            </div>
        </div>
    </div>

    <!-- Charges Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Charges</h6>
            <small class="text-muted"><?= count($charges) ?> charge(s)</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Percent</th>
                            <th>Created (BS)</th>
                            <th>Updated (BS)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="chargesTableBody">
                        <?php foreach ($charges as $i => $c): ?>
                            <tr data-id="<?= $c['id'] ?>">
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($c['name']) ?></td>
                                <td><?= number_format($c['percent'], 2) ?>%</td>
                                <td><?= display_nepali_date($c['created_at']) ?></td>
                                <td><?= display_nepali_date($c['updated_at']) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-warning editBtn"
                                            data-id="<?= $c['id'] ?>"
                                            data-name="<?= htmlspecialchars($c['name']) ?>"
                                            data-percent="<?= $c['percent'] ?>">
                                        Edit
                                    </button>
                                    <button class="btn btn-sm btn-danger deleteBtn"
                                            data-id="<?= $c['id'] ?>"
                                            data-name="<?= htmlspecialchars($c['name']) ?>">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($charges)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No charges added yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade modal-confirm" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="mb-0">Are you sure you want to delete the charge:</p>
                <strong id="deleteChargeName" class="text-danger"></strong>?
                <p class="text-muted small mt-2">This action cannot be undone.</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="deleteForm" method="POST" class="d-inline">
                    <input type="hidden" name="delete_charge" value="1">
                    <input type="hidden" name="delete_id" id="deleteId">
                    <button type="submit" class="btn btn-danger">Delete Charge</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../Common/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    const deleteForm = document.getElementById('deleteForm');
    const deleteIdInput = document.getElementById('deleteId');
    const deleteNameSpan = document.getElementById('deleteChargeName');
    const chargeForm = document.getElementById('chargeForm');
    const chargeIdInput = document.getElementById('charge_id');
    const nameInput = document.getElementById('name');
    const percentInput = document.getElementById('percent');
    const submitBtn = document.getElementById('submitBtn');
    const clearBtn = document.getElementById('clearBtn');

    // === Edit Button ===
    document.querySelectorAll('.editBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            chargeIdInput.value = btn.dataset.id;
            nameInput.value = btn.dataset.name;
            percentInput.value = btn.dataset.percent;
            submitBtn.textContent = 'Update';
        });
    });

    // === Clear Button ===
    clearBtn.addEventListener('click', () => {
        chargeIdInput.value = '';
        nameInput.value = '';
        percentInput.value = '';
        submitBtn.textContent = 'Add Charge';
        nameInput.focus();
    });

    // === Delete Button ===
    document.querySelectorAll('.deleteBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            deleteIdInput.value = btn.dataset.id;
            deleteNameSpan.textContent = btn.dataset.name;
            modal.show();
        });
    });

    // === Delete via AJAX ===
    deleteForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);

        fetch('', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.querySelector(`tr[data-id="${deleteIdInput.value}"]`).remove();
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message, 'danger');
                }
                modal.hide();
            })
            .catch(() => {
                showToast('Network error.', 'danger');
                modal.hide();
            });
    });

    // === Toast Helper ===
    function showToast(msg, type) {
        const toast = `
            <div class="toast align-items-center text-white bg-${type} border-0 position-fixed top-50 start-50 translate-middle" role="alert" style="z-index: 1100;">
                <div class="d-flex">
                    <div class="toast-body">${msg}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>`;
        document.body.insertAdjacentHTML('beforeend', toast);
        const t = new bootstrap.Toast(document.querySelector('.toast'));
        t.show();
        setTimeout(() => document.querySelector('.toast').remove(), 3000);
    }

    // Auto-dismiss alerts
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => new bootstrap.Alert(alert).close(), 8000);
    });
});
</script>
</body>
</html>