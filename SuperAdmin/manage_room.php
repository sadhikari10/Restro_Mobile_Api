<?php
// SuperAdmin/manage_room.php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin' || empty($_SESSION['current_restaurant_id'])) {
    header('Location: ../login.php');
    exit;
}

$restaurant_id = $_SESSION['current_restaurant_id'];
$success_msg = '';
$error_msg = '';

// ==================== HANDLE POST ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['save_package'])) {
        $pkg_id     = !empty($_POST['package_id']) ? (int)$_POST['package_id'] : null;
        $room_id    = (int)($_POST['room_id'] ?? 0);
        $name       = trim($_POST['package_name'] ?? '');
        $features   = trim($_POST['features'] ?? '');
        $price      = (float)($_POST['price'] ?? 0);
        $status     = $_POST['status'] ?? 'available';

        if ($room_id <= 0) {
            $error_msg = "Room ID is required.";
        } elseif (empty($name)) {
            $error_msg = "Package name is required.";
        } elseif ($price <= 0) {
            $error_msg = "Price must be greater than 0.";
        } else {
            if ($pkg_id) {
                // Update
                $stmt = $conn->prepare("UPDATE room_packages 
                    SET room_id=?, package_name=?, features=?, price=?, status=? 
                    WHERE id=? AND restaurant_id=?");
                if ($stmt) {
                    $stmt->bind_param("issdsii", $room_id, $name, $features, $price, $status, $pkg_id, $restaurant_id);
                    if ($stmt->execute()) {
                        $success_msg = "Package updated successfully!";
                    } else {
                        $error_msg = "Update failed: " . $conn->error;
                    }
                    $stmt->close();
                }
            } else {
                // Insert
                $stmt = $conn->prepare("INSERT INTO room_packages 
                    (restaurant_id, room_id, package_name, features, price, status) 
                    VALUES (?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("iissds", $restaurant_id, $room_id, $name, $features, $price, $status);
                    if ($stmt->execute()) {
                        $success_msg = "New package added successfully!";
                    } else {
                        $error_msg = "Insert failed: " . $conn->error;
                    }
                    $stmt->close();
                } else {
                    $error_msg = "Prepare failed: " . $conn->error;
                }
            }
        }
    }

    // DELETE
    if (isset($_POST['delete_package'])) {
        $pkg_id = (int)($_POST['package_id'] ?? 0);
        if ($pkg_id > 0) {
            $stmt = $conn->prepare("DELETE FROM room_packages WHERE id = ? AND restaurant_id = ?");
            if ($stmt) {
                $stmt->bind_param("ii", $pkg_id, $restaurant_id);
                if ($stmt->execute()) {
                    $success_msg = "Package deleted successfully!";
                } else {
                    $error_msg = "Delete failed.";
                }
                $stmt->close();
            }
        }
    }
}

// ==================== FETCH PACKAGES ====================
$stmt = $conn->prepare("SELECT * FROM room_packages WHERE restaurant_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$packages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Room Packages</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-success"><i class="bi bi-gift me-2"></i>Room Packages</h3>
        <div>
            <button class="btn btn-success" onclick="openPackageModal()">
                <i class="bi bi-plus-lg"></i> Add New Package
            </button>
            <a href="view_branch.php" class="btn btn-secondary">Back</a>
        </div>
    </div>

    <?php if($success_msg): ?><div class="alert alert-success"><?= $success_msg ?></div><?php endif; ?>
    <?php if($error_msg): ?><div class="alert alert-danger"><?= $error_msg ?></div><?php endif; ?>

    <div class="row g-4">
        <?php if (empty($packages)): ?>
            <div class="col-12 text-center py-5 text-muted">
                <h5>No packages found. Add your first package.</h5>
            </div>
        <?php else: ?>
            <?php foreach ($packages as $pkg): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <span class="badge bg-<?= $pkg['status'] == 'available' ? 'success' : 'warning' ?>">
                                <?= ucfirst($pkg['status']) ?>
                            </span>
                            <div>
                                <button class="btn btn-sm btn-outline-primary me-1" onclick='openPackageModal(<?= json_encode($pkg) ?>)'>Edit</button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deletePackage(<?= $pkg['id'] ?>, '<?= addslashes($pkg['package_name']) ?>')">Delete</button>
                            </div>
                        </div>
                        <h5 class="mt-3"><?= htmlspecialchars($pkg['package_name']) ?></h5>
                        <p class="small text-muted"><?= nl2br(htmlspecialchars($pkg['features'] ?? '')) ?></p>
                        <h5 class="text-success">Rs. <?= number_format($pkg['price'], 2) ?></h5>
                        <small class="text-muted">Room ID: <?= $pkg['room_id'] ?></small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="packageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Package</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="package_id" id="package_id">

                <div class="mb-3">
                    <label class="form-label">Room ID</label>
                    <input type="number" name="room_id" id="room_id" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Package Name</label>
                    <input type="text" name="package_name" id="package_name" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Features</label>
                    <textarea name="features" id="features" class="form-control" rows="3"></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">Price (Rs)</label>
                    <input type="number" name="price" id="price" class="form-control" step="0.01" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="available">Available</option>
                        <option value="not available">Not Available</option>
                        <option value="maintainance">Maintenance</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="save_package" class="btn btn-success">Save Package</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const modal = new bootstrap.Modal(document.getElementById('packageModal'));

function openPackageModal(data = null) {
    document.getElementById('modalTitle').innerText = data ? 'Edit Package' : 'Add New Package';
    
    document.getElementById('package_id').value = data ? data.id : '';
    document.getElementById('room_id').value = data ? data.room_id : '';
    document.getElementById('package_name').value = data ? data.package_name : '';
    document.getElementById('features').value = data ? data.features : '';
    document.getElementById('price').value = data ? data.price : '';
    document.getElementById('status').value = data ? data.status : 'available';

    modal.show();
}

function deletePackage(id, name) {
    if (confirm(`Delete package "${name}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="delete_package" value="1">
            <input type="hidden" name="package_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>