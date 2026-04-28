<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

date_default_timezone_set('Asia/Kathmandu');

// === SECURITY: Must be superadmin + have a branch selected ===
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../Common/login.php');
    exit;
}

if (empty($_SESSION['current_restaurant_id'])) {
    header('Location: index.php');
    exit;
}

$restaurant_id = $_SESSION['current_restaurant_id'];
$message = '';
$error = '';

// --- HANDLE DELETE ---
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Double check restaurant_id to prevent deleting tables from other branches via URL manipulation
    $stmt = $conn->prepare("DELETE FROM restaurant_tables WHERE id = ? AND restaurant_id = ?");
    $stmt->bind_param("ii", $id, $restaurant_id);
    if ($stmt->execute()) {
        header("Location: add_table.php?msg=deleted");
        exit;
    }
    $stmt->close();
}

// Show success message after redirect
if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $message = "Table deleted successfully.";
}

// --- HANDLE ADD / EDIT (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $table_name = trim($_POST['table_name'] ?? '');
    $edit_id = !empty($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;

    if (empty($table_name)) {
        $error = "Table name cannot be empty.";
    } else {
        if ($edit_id) {
            // Update Existing
            $stmt = $conn->prepare("UPDATE restaurant_tables SET table_name = ? WHERE id = ? AND restaurant_id = ?");
            $stmt->bind_param("sii", $table_name, $edit_id, $restaurant_id);
        } else {
            // Insert New
            $stmt = $conn->prepare("INSERT INTO restaurant_tables (restaurant_id, table_name) VALUES (?, ?)");
            $stmt->bind_param("is", $restaurant_id, $table_name);
        }

        try {
            if ($stmt->execute()) {
                $message = $edit_id ? "Table updated successfully." : "New table added successfully.";
            }
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1062) { // Duplicate entry code
                $error = "The table name '<strong>$table_name</strong>' already exists in this branch.";
            } else {
                $error = "Error saving table: " . $e->getMessage();
            }
        }
        $stmt->close();
    }
}

// --- FETCH ALL TABLES FOR THIS BRANCH ---
$stmt = $conn->prepare("SELECT id, table_name FROM restaurant_tables WHERE restaurant_id = ? ORDER BY table_name ASC");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$tables = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Tables - SuperAdmin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .table-container { max-width: 700px; margin: 0 auto; }
        .card { border: none; border-radius: 12px; }
        .card-header { border-radius: 12px 12px 0 0 !important; }
    </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container mt-5">
    <div class="table-container">
        
        <h3 class="mb-4 text-center">Manage Branch Tables</h3>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white py-3">
                <h6 class="mb-0" id="formTitle"><i class="bi bi-plus-circle me-2"></i>Add New Table</h6>
            </div>
            <div class="card-body p-4">
                <?php if($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= $error ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" id="tableForm">
                    <input type="hidden" name="edit_id" id="edit_id">
                    <div class="row g-3">
                        <div class="col-sm-9">
                            <input type="text" name="table_name" id="table_name" class="form-control form-control-lg" 
                                   placeholder="e.g. Table 1, Cabin A, Rooftop 5" required>
                        </div>
                        <div class="col-sm-3">
                            <button type="submit" class="btn btn-primary btn-lg w-100" id="submitBtn">Save</button>
                        </div>
                    </div>
                    <div id="editCancelContainer" class="mt-2" style="display: none;">
                        <button type="button" class="btn btn-sm btn-link text-muted" onclick="resetForm()">Cancel Edit</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Table Name</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tables)): ?>
                            <tr>
                                <td colspan="2" class="text-center py-4 text-muted">No tables added for this branch yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tables as $t): ?>
                            <tr>
                                <td class="ps-4 fw-bold"><?= htmlspecialchars($t['table_name']) ?></td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-outline-info me-2" 
                                            onclick="editTable(<?= $t['id'] ?>, '<?= addslashes($t['table_name']) ?>')">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="confirmDelete(<?= $t['id'] ?>, '<?= addslashes($t['table_name']) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4 text-center">
            <a href="view_branch.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Branch View
            </a>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Confirm Deletion</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center py-4">
        <div class="mb-3">
            <i class="bi bi-exclamation-octagon text-danger" style="font-size: 3rem;"></i>
        </div>
        <p class="fs-5">Are you sure you want to delete <br><strong id="deleteTableName" class="text-dark"></strong>?</p>
        <p class="text-muted small mb-0">This table will no longer appear in the "Take Order" dropdown.</p>
      </div>
      <div class="modal-footer justify-content-center border-0 pb-4">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
        <a id="finalDeleteBtn" href="#" class="btn btn-danger px-4">Yes, Delete It</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const formTitle = document.getElementById('formTitle');
    const submitBtn = document.getElementById('submitBtn');
    const editIdInput = document.getElementById('edit_id');
    const tableNameInput = document.getElementById('table_name');
    const cancelContainer = document.getElementById('editCancelContainer');

    // Switch form to Edit Mode
    function editTable(id, name) {
        formTitle.innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Table Name';
        submitBtn.textContent = 'Update';
        submitBtn.className = 'btn btn-info btn-lg w-100 text-white';
        editIdInput.value = id;
        tableNameInput.value = name;
        cancelContainer.style.display = 'block';
        tableNameInput.focus();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Reset form to Add Mode
    function resetForm() {
        formTitle.innerHTML = '<i class="bi bi-plus-circle me-2"></i>Add New Table';
        submitBtn.textContent = 'Save';
        submitBtn.className = 'btn btn-primary btn-lg w-100';
        editIdInput.value = '';
        tableNameInput.value = '';
        cancelContainer.style.display = 'none';
    }

    // Modal Trigger Logic
    function confirmDelete(id, name) {
        document.getElementById('deleteTableName').textContent = name;
        document.getElementById('finalDeleteBtn').href = "?delete=" + id;
        
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
        deleteModal.show();
    }
</script>

<?php include '../Common/footer.php'; ?>
</body>
</html>