<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

// SESSION CHECK (admin-only)
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || empty($_SESSION['restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

$restaurant_id = $_SESSION['restaurant_id'];

/* ==================== FETCH CHAIN_ID FROM RESTAURANT ==================== */
$stmt = $conn->prepare("SELECT chain_id FROM restaurants WHERE id = ?");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$chain_result = $stmt->get_result();
$chain_row = $chain_result->fetch_assoc();
$stmt->close();

if (!$chain_row || empty($chain_row['chain_id'])) {
    die("Error: Restaurant not found or chain_id missing.");
}
$chain_id = $chain_row['chain_id'];

/* ==================== MESSAGES FROM SESSION ==================== */
$error_message = $_SESSION['staff_error'] ?? '';
$success_message = $_SESSION['staff_success'] ?? '';

// Clear after use
unset($_SESSION['staff_error'], $_SESSION['staff_success']);

/* ==================== SEARCH ==================== */
$search_username = trim($_GET['search'] ?? '');

/* ==================== CRUD HANDLING ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = $_POST['phone'] ?? '';
    $role     = $_POST['role'] ?? 'staff';
    $status   = $_POST['status'] ?? 'active';

    $errors = [];
    if (empty($username)) $errors[] = 'Username is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (!empty($phone) && !ctype_digit($phone)) $errors[] = 'Phone must be numeric.';
    if ($role !== 'staff') $errors[] = 'Only staff role is allowed.';

    $exclude_id = ($action === 'edit') ? (int)$_POST['id'] : 0;

    if (empty($errors)) {
        $check_stmt = $conn->prepare("
            SELECT id FROM users 
            WHERE restaurant_id = ? AND (email = ? OR phone = ?) AND id != ?
        ");
        $check_stmt->bind_param("issi", $restaurant_id, $email, $phone, $exclude_id);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $errors[] = 'Email or phone already in use.';
        }
        $check_stmt->close();
    }

    if (empty($errors)) {
        if ($action === 'add') {
            $created_at_bs = nepali_date_time();
            $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                INSERT INTO users 
                (restaurant_id, chain_id, username, password, email, phone, role, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "iisssssss",
                $restaurant_id,
                $chain_id,           // FIXED: chain_id now inserted
                $username,
                $password_hash,
                $email,
                $phone,
                $role,
                $status,
                $created_at_bs
            );

            if ($stmt->execute()) {
                $_SESSION['staff_success'] = 'Staff member added successfully!';
            } else {
                $errors[] = 'Database error: ' . $stmt->error;
            }
            $stmt->close();

        } elseif ($action === 'edit') {
            $id = (int)$_POST['id'];

            $stmt = $conn->prepare("
                UPDATE users 
                SET username=?, email=?, phone=?, role=?, status=? 
                WHERE id=? AND restaurant_id=?
            ");
            $stmt->bind_param(
                "sssssii",
                $username, $email, $phone, $role, $status, $id, $restaurant_id
            );

            if ($stmt->execute()) {
                $_SESSION['staff_success'] = 'Staff member updated successfully!';
            } else {
                $errors[] = 'Update failed.';
            }
            $stmt->close();
        }
    }

    if (!empty($errors)) {
        $_SESSION['staff_error'] = implode('<br>', $errors);
    }

    $redirect = "staff_detail.php" . ($search_username ? "?search=" . urlencode($search_username) : "");
    header("Location: $redirect");
    exit;
}

/* ==================== DELETE STAFF ==================== */
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id=? AND restaurant_id=? AND role='staff'");
    $stmt->bind_param("ii", $id, $restaurant_id);
    if ($stmt->execute()) {
        $_SESSION['staff_success'] = 'Staff member deleted successfully!';
    } else {
        $_SESSION['staff_error'] = 'Failed to delete staff.';
    }
    $stmt->close();

    $redirect = "staff_detail.php" . ($search_username ? "?search=" . urlencode($search_username) : "");
    header("Location: $redirect");
    exit;
}

/* ==================== FETCH STAFF (role != admin) ==================== */
$search_clause = '';
$params = [$restaurant_id];
$types = 'i';

if ($search_username) {
    $search_clause = " AND username LIKE ?";
    $params[] = "%$search_username%";
    $types .= 's';
}

$query = "SELECT * FROM users WHERE restaurant_id = ? AND role != 'admin' $search_clause ORDER BY id DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($_SESSION['restaurant_name'] ?? 'Staff Management') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="admin.css" rel="stylesheet">
<style>
    #floatingAlert { position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; display: none; }
    #alertBackdrop { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.3); z-index: 9998; display: none; }
</style>
</head>
<body class="menu-body">

<!-- Floating Alert -->
<div id="alertBackdrop"></div>
<div id="floatingAlert" class="shadow-lg">
    <div id="alertContent"></div>
    <button id="closeAlert" class="btn-close position-absolute top-0 end-0 m-2"></button>
</div>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-light header">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php"><?= htmlspecialchars($_SESSION['restaurant_name']) ?></a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="dashboard.php">Dashboard</a>
            <a class="nav-link" href="../Common/logout.php">Logout</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <h3 class="mb-4 text-center text-success">Staff Management</h3>

    <a href="dashboard.php" class="btn btn-secondary mb-3 btn-custom">Back to Dashboard</a>

    <!-- Search -->
    <form method="GET" class="mb-3">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="Search staff by username..." value="<?= htmlspecialchars($search_username) ?>">
            <button class="btn btn-outline-secondary" type="submit">Search</button>
            <?php if ($search_username): ?>
                <a href="staff_detail.php" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Add Staff Form -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">Add New Staff</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="staff_detail.php">
                <input type="hidden" name="action" value="add">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="password" id="addPassword" class="form-control" required>
                            <button type="button" id="toggleAddPassword" class="btn btn-outline-secondary">
                                <i id="addPasswordIcon" class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" required>
                            <option value="staff">Staff</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-success">Add Staff</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Staff Table -->
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= $row['id'] ?></td>
                    <td><strong><?= htmlspecialchars($row['username']) ?></strong></td>
                    <td><?= htmlspecialchars($row['email']) ?></td>
                    <td><?= htmlspecialchars($row['phone'] ?? '-') ?></td>
                    <td>
                        <span class="badge <?= $row['status']=='active' ? 'bg-success' : 'bg-secondary' ?>">
                            <?= ucfirst($row['status']) ?>
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['id'] ?>">Edit</button>
                        <a href="staff_detail.php?action=delete&id=<?= $row['id'] ?>" 
                           class="btn btn-sm btn-danger" 
                           onclick="return confirm('Delete this staff member?')">Delete</a>
                    </td>
                </tr>

                <!-- Edit Modal -->
                <div class="modal fade" id="editModal<?= $row['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form method="POST" action="staff_detail.php">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <div class="modal-header bg-warning text-dark">
                                    <h5 class="modal-title">Edit Staff</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Username</label>
                                        <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($row['username']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($row['email']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Phone</label>
                                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($row['phone']) ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Role</label>
                                        <select name="role" class="form-select" required>
                                            <option value="staff" <?= $row['role']=='staff' ? 'selected':'' ?>>Staff</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="active" <?= $row['status']=='active'? 'selected':'' ?>>Active</option>
                                            <option value="inactive" <?= $row['status']=='inactive'? 'selected':'' ?>>Inactive</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Update Staff</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('toggleAddPassword').addEventListener('click', function() {
    const input = document.getElementById('addPassword');
    const icon = document.getElementById('addPasswordIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
});

function showFloatingAlert(content, type) {
    const alert = document.getElementById('floatingAlert');
    const contentDiv = document.getElementById('alertContent');
    const backdrop = document.getElementById('alertBackdrop');
    contentDiv.innerHTML = content;
    alert.className = 'alert alert-' + type + ' shadow-lg';
    alert.style.display = 'block';
    backdrop.style.display = 'block';
    setTimeout(() => { alert.style.display = 'none'; backdrop.style.display = 'none'; }, 5000);
}

document.getElementById('closeAlert').addEventListener('click', () => {
    document.getElementById('floatingAlert').style.display = 'none';
    document.getElementById('alertBackdrop').style.display = 'none';
});
document.getElementById('alertBackdrop').addEventListener('click', () => {
    document.getElementById('closeAlert').click();
});

<?php if ($error_message): ?>
document.addEventListener('DOMContentLoaded', () => {
    showFloatingAlert('<?= addslashes($error_message) ?>', 'danger');
});
<?php endif; ?>

<?php if ($success_message): ?>
document.addEventListener('DOMContentLoaded', () => {
    showFloatingAlert('<?= addslashes($success_message) ?>', 'success');
});
<?php endif; ?>
</script>

<?php include '../Common/footer.php'; ?>
</body>
</html>