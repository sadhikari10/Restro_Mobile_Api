<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

// === SECURITY: Must be superadmin + have session ID ===
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: ../login.php');
    exit;
}

if (empty($_SESSION['current_restaurant_id'])) {
    header('Location: index.php');
    exit;
}

$restaurant_id = $_SESSION['current_restaurant_id'];

/* ==================== HANDLE POST (ADD / EDIT) ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $item_name   = trim($_POST['item_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category    = trim($_POST['category'] ?? ''); // Now free text
    $price       = (float)$_POST['price'];
    $status      = $_POST['status'] ?? 'not_available';

    if ($action === 'add') {
        $created_at = nepali_date_time();
        $stmt = $conn->prepare("
            INSERT INTO menu_items 
            (restaurant_id, item_name, description, category, price, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssdss", $restaurant_id, $item_name, $description, $category, $price, $status, $created_at);
        $stmt->execute();
        $stmt->close();
    }
    elseif ($action === 'edit' && !empty($_POST['id'])) {
        $id = (int)$_POST['id'];

        $stmt = $conn->prepare("
            UPDATE menu_items 
            SET item_name = ?, description = ?, category = ?, price = ?, status = ? 
            WHERE id = ? AND restaurant_id = ?
        ");
        $stmt->bind_param("sssdsii", $item_name, $description, $category, $price, $status, $id, $restaurant_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: menu_items.php");
    exit;
}

/* ==================== HANDLE DELETE ==================== */
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("DELETE FROM menu_items WHERE id = ? AND restaurant_id = ?");
    $stmt->bind_param("ii", $id, $restaurant_id);
    $stmt->execute();
    $stmt->close();
    header("Location: menu_items.php");
    exit;
}

/* ==================== FETCH MENU ITEMS ==================== */
$stmt = $conn->prepare("SELECT * FROM menu_items WHERE restaurant_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Menu Items - SuperAdmin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="../admin.css" rel="stylesheet">
</head>
<body class="menu-body">
    <?php include('navbar.php'); ?>

<div class="container mt-4">
    <h3 class="mb-4 text-center text-success">Menu Items Management</h3>

    <a href="view_branch.php" class="btn btn-secondary mb-3 btn-custom">Back</a>
    <button class="btn btn-success mb-3 btn-custom" data-bs-toggle="collapse" data-bs-target="#addForm">Add New Item</button>

    <!-- Add Form -->
    <div class="collapse mb-4" id="addForm">
        <form method="POST" action="menu_items.php">
            <input type="hidden" name="action" value="add">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Item Name <span class="text-danger">*</span></label>
                    <input type="text" name="item_name" class="form-control" placeholder="e.g., Chicken Momo" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Price (Rs) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" name="price" class="form-control" placeholder="e.g., 150.00" required>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <label class="form-label">Category</label>
                    <input type="text" name="category" class="form-control" placeholder="e.g., Veg, Non-Veg, Beverage, Dessert">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="available">Available</option>
                        <option value="not_available">Not Available</option>
                    </select>
                </div>
            </div>
            <div class="mt-2">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control" placeholder="Optional description">
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary btn-custom">Add Item</button>
            </div>
        </form>
    </div>

    <!-- Menu Items Table -->
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($row['item_name']) ?></strong></td>
                    <td><?= htmlspecialchars($row['description'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['category'] ?? '—') ?></td>
                    <td>Rs <?= number_format($row['price'], 2) ?></td>
                    <td>
                        <span class="badge <?= $row['status'] === 'available' ? 'bg-success' : 'bg-danger' ?>">
                            <?= ucfirst(str_replace('_', ' ', $row['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['id'] ?>">Edit</button>
                        <a href="menu_items.php?action=delete&id=<?= $row['id'] ?>" 
                           class="btn btn-sm btn-danger" 
                           onclick="return confirm('Are you sure you want to delete this item?')">Delete</a>
                    </td>
                </tr>

                <!-- Edit Modal -->
                <div class="modal fade" id="editModal<?= $row['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form method="POST" action="menu_items.php">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <div class="modal-header bg-warning text-dark">
                                    <h5 class="modal-title">Edit Item #<?= $row['id'] ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Item Name <span class="text-danger">*</span></label>
                                        <input type="text" name="item_name" class="form-control" value="<?= htmlspecialchars($row['item_name']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($row['description'] ?? '') ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Category</label>
                                        <input type="text" name="category" class="form-control" 
                                               value="<?= htmlspecialchars($row['category'] ?? '') ?>" 
                                               placeholder="e.g., Veg, Non-Veg, Beverage">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Price <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" name="price" class="form-control" value="<?= $row['price'] ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="available" <?= $row['status'] === 'available' ? 'selected' : '' ?>>Available</option>
                                            <option value="not_available" <?= $row['status'] === 'not_available' ? 'selected' : '' ?>>Not Available</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Update Item</button>
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
<?php include '../Common/footer.php'; ?>
</body>
</html>