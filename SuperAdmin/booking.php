<?php
// SuperAdmin/book_room.php
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

// ==================== HANDLE BOOKING ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_package'])) {
    $package_id     = (int)$_POST['package_id'];
    $guest_name     = trim($_POST['guest_name'] ?? '');
    $id_type        = $_POST['id_type'] ?? '';
    $id_number      = trim($_POST['id_number'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $mobile_number  = trim($_POST['mobile_number'] ?? '');
    $room_category  = trim($_POST['package_name'] ?? '');
    $checkin_date   = $_POST['checkin_date'] ?? '';
    $checkout_date  = $_POST['checkout_date'] ?? '';
    $total_amount   = (float)$_POST['total_amount'] ?? 0;
    $advance_paid   = (float)$_POST['advance_paid'] ?? 0;

    // Calculate duration
    $duration = 0;
    if ($checkin_date && $checkout_date) {
        $diff = strtotime($checkout_date) - strtotime($checkin_date);
        $duration = (int)($diff / (60 * 60 * 24));
    }

    // Handle ID Photo Upload
    $id_photo = '';
    if (isset($_FILES['id_photo']) && $_FILES['id_photo']['error'] == 0) {
        $upload_dir = '../Images/';
        $file_ext = strtolower(pathinfo($_FILES['id_photo']['name'], PATHINFO_EXTENSION));
        $new_filename = 'id_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
        $target_file = $upload_dir . $new_filename;

        if (move_uploaded_file($_FILES['id_photo']['tmp_name'], $target_file)) {
            $id_photo = $new_filename;
        }
    }

    if (empty($guest_name) || empty($mobile_number) || empty($checkin_date)) {
        $error_msg = "Guest name, mobile number and check-in date are required.";
    } elseif ($duration <= 0) {
        $error_msg = "Check-out date must be after check-in date.";
    } else {
        $stmt = $conn->prepare("INSERT INTO booking 
            (restaurant_id, guest_name, id_type, id_number, id_photo, email, mobile_number, room_category, 
             checkin_date, checkout_date, duration_of_stay, total_amount, advance_paid, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?)");
        
        $created_at = nepali_date_time();

        $stmt->bind_param("issssssssssdds", 
            $restaurant_id, $guest_name, $id_type, $id_number, $id_photo, $email, $mobile_number, 
            $room_category, $checkin_date, $checkout_date, $duration, $total_amount, $advance_paid, $created_at
        );

        if ($stmt->execute()) {
            $update = $conn->prepare("UPDATE room_packages SET status = 'not available' WHERE id = ? AND restaurant_id = ?");
            $update->bind_param("ii", $package_id, $restaurant_id);
            $update->execute();
            $update->close();

            $success_msg = "Booking created successfully!";
        } else {
            $error_msg = "Failed to create booking: " . $conn->error;
        }
        $stmt->close();
    }
}

// Fetch All Packages
$stmt = $conn->prepare("SELECT * FROM room_packages WHERE restaurant_id = ? ORDER BY package_name ASC");
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
    <title>Book Room / Package</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-success"><i class="bi bi-calendar-plus"></i> Book Room / Package</h2>
        <a href="view_branch.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <?php if($success_msg): ?><div class="alert alert-success"><?= $success_msg ?></div><?php endif; ?>
    <?php if($error_msg): ?><div class="alert alert-danger"><?= $error_msg ?></div><?php endif; ?>

    <div class="row g-4">
        <?php foreach ($packages as $pkg): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <span class="badge <?= $pkg['status'] == 'available' ? 'bg-success' : 'bg-danger' ?>">
                            <?= ucfirst($pkg['status']) ?>
                        </span>
                        <span class="fw-bold text-success">Rs. <?= number_format($pkg['price'], 2) ?></span>
                    </div>
                    <h5 class="mt-3"><?= htmlspecialchars($pkg['package_name']) ?></h5>
                    <p class="small text-muted"><?= nl2br(htmlspecialchars($pkg['features'] ?? '')) ?></p>

                    <?php if ($pkg['status'] == 'available'): ?>
                        <button class="btn btn-primary w-100 mt-3" 
                                onclick="bookPackage(<?= $pkg['id'] ?>, '<?= addslashes($pkg['package_name']) ?>', <?= $pkg['price'] ?>)">
                            Book Now
                        </button>
                    <?php else: ?>
                        <button class="btn btn-secondary w-100 mt-3" disabled>Not Available</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Booking Modal -->
<div class="modal fade" id="bookingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Booking - <span id="modalPackageName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="package_id" id="package_id_input">
                <input type="hidden" name="package_name" id="package_name_input">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>Guest Name *</label>
                        <input type="text" name="guest_name" class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>Mobile Number *</label>
                        <input type="text" name="mobile_number" class="form-control" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>ID Type</label>
                        <select name="id_type" class="form-select">
                            <option value="citizenship">Citizenship</option>
                            <option value="passport">Passport</option>
                            <option value="driving_license">Driving License</option>
                            <option value="national_id">National ID</option>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>ID Number</label>
                        <input type="text" name="id_number" class="form-control">
                    </div>
                </div>

                <div class="mb-3">
                    <label>ID Photo (Optional)</label>
                    <input type="file" name="id_photo" class="form-control" accept="image/*">
                </div>

                <div class="mb-3">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control">
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label>Check-in Date *</label>
                        <input type="date" name="checkin_date" id="checkin_date" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label>Check-out Date *</label>
                        <input type="date" name="checkout_date" id="checkout_date" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label>Duration (Nights)</label>
                        <input type="number" name="duration_of_stay" id="duration_of_stay" class="form-control" readonly>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>Total Amount (Rs)</label>
                        <input type="number" name="total_amount" id="total_amount" class="form-control" step="0.01" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>Advance Paid (Rs)</label>
                        <input type="number" name="advance_paid" class="form-control" step="0.01" value="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="book_package" class="btn btn-success">Confirm Booking</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function bookPackage(id, name, price) {
    document.getElementById('modalPackageName').textContent = name;
    document.getElementById('package_id_input').value = id;
    document.getElementById('package_name_input').value = name;
    document.getElementById('total_amount').value = price;
    new bootstrap.Modal(document.getElementById('bookingModal')).show();
}

// Auto calculate duration
document.addEventListener('DOMContentLoaded', function() {
    const checkout = document.getElementById('checkout_date');
    if (checkout) {
        checkout.addEventListener('change', function() {
            const checkin = document.getElementById('checkin_date').value;
            if (checkin && this.value) {
                const diffTime = Math.abs(new Date(this.value) - new Date(checkin));
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                document.getElementById('duration_of_stay').value = diffDays;
            }
        });
    }
});
</script>

</body>
</html>