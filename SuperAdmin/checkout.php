<?php
// SuperAdmin/checkout.php
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

// Fetch Packages for Dropdown
$pkg_stmt = $conn->prepare("SELECT package_name FROM room_packages WHERE restaurant_id = ? ORDER BY package_name ASC");
$pkg_stmt->bind_param("i", $restaurant_id);
$pkg_stmt->execute();
$all_packages = $pkg_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pkg_stmt->close();

// ==================== HANDLE EDIT ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_booking'])) {
    $booking_id     = (int)$_POST['booking_id'];
    $guest_name     = trim($_POST['guest_name'] ?? '');
    $id_type        = $_POST['id_type'] ?? '';
    $id_number      = trim($_POST['id_number'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $mobile_number  = trim($_POST['mobile_number'] ?? '');
    $new_room_category = trim($_POST['room_category'] ?? '');
    $checkin_date   = $_POST['checkin_date'] ?? '';
    $checkout_date  = $_POST['checkout_date'] ?? '';
    $total_amount   = (float)$_POST['total_amount'] ?? 0;
    $advance_paid   = (float)$_POST['advance_paid'] ?? 0;
    $status         = $_POST['status'] ?? 'confirmed';

    $duration = 0;
    if ($checkin_date && $checkout_date) {
        $diff = strtotime($checkout_date) - strtotime($checkin_date);
        $duration = (int)($diff / (60 * 60 * 24));
    }

    // Get old data
    $old_stmt = $conn->prepare("SELECT room_category, id_photo FROM booking WHERE id = ?");
    $old_stmt->bind_param("i", $booking_id);
    $old_stmt->execute();
    $old = $old_stmt->get_result()->fetch_assoc();
    $old_room_category = $old['room_category'] ?? '';
    $old_photo = $old['id_photo'] ?? '';
    $old_stmt->close();

    // Handle ID Photo
    $id_photo = $old_photo;
    if (isset($_FILES['id_photo']) && $_FILES['id_photo']['error'] == 0) {
        $upload_dir = '../Images/';
        $file_ext = strtolower(pathinfo($_FILES['id_photo']['name'], PATHINFO_EXTENSION));
        $new_filename = 'id_' . time() . '_' . rand(1000,9999) . '.' . $file_ext;
        $target = $upload_dir . $new_filename;
        if (move_uploaded_file($_FILES['id_photo']['tmp_name'], $target)) {
            $id_photo = $new_filename;
        }
    }

    // Update Booking
    $stmt = $conn->prepare("UPDATE booking SET guest_name=?, id_type=?, id_number=?, id_photo=?, email=?, 
        mobile_number=?, room_category=?, checkin_date=?, checkout_date=?, duration_of_stay=?, 
        total_amount=?, advance_paid=?, status=?, updated_at=? 
        WHERE id=? AND restaurant_id=?");
    
    $now = nepali_date_time();
    $stmt->bind_param("sssssssssiddsssi", $guest_name, $id_type, $id_number, $id_photo, $email, 
        $mobile_number, $new_room_category, $checkin_date, $checkout_date, $duration, 
        $total_amount, $advance_paid, $status, $now, $booking_id, $restaurant_id);

    if ($stmt->execute()) {
        // Handle Package Status Change
        if ($new_room_category && $new_room_category !== $old_room_category) {
            // Old package → Available
            $conn->query("UPDATE room_packages SET status = 'available' 
                WHERE package_name = '$old_room_category' AND restaurant_id = $restaurant_id LIMIT 1");
            
            // New package → Not Available
            $conn->query("UPDATE room_packages SET status = 'not available' 
                WHERE package_name = '$new_room_category' AND restaurant_id = $restaurant_id LIMIT 1");
        }

        $success_msg = "Booking updated successfully!";
    } else {
        $error_msg = "Update failed: " . $conn->error;
    }
    $stmt->close();
}

// ==================== HANDLE CHECKOUT ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout_booking'])) {
    $booking_id = (int)$_POST['booking_id'];

    $get = $conn->prepare("SELECT room_category FROM booking WHERE id = ? AND restaurant_id = ?");
    $get->bind_param("ii", $booking_id, $restaurant_id);
    $get->execute();
    $booking = $get->get_result()->fetch_assoc();
    $get->close();

    if ($booking) {
        $stmt = $conn->prepare("UPDATE booking SET status = 'checked_out', updated_at = ? WHERE id = ? AND restaurant_id = ?");
        $now = nepali_date_time();
        $stmt->bind_param("sii", $now, $booking_id, $restaurant_id);
        
        if ($stmt->execute()) {
            $update_pkg = $conn->prepare("UPDATE room_packages SET status = 'available' 
                WHERE package_name = ? AND restaurant_id = ? LIMIT 1");
            $update_pkg->bind_param("si", $booking['room_category'], $restaurant_id);
            $update_pkg->execute();
            $update_pkg->close();

            $success_msg = "Guest checked out successfully! Package is now available.";
        }
        $stmt->close();
    }
}

// Fetch Active Bookings
$stmt = $conn->prepare("SELECT * FROM booking 
    WHERE restaurant_id = ? 
    AND status IN ('confirmed', 'checked_in') 
    ORDER BY checkin_date ASC");
$stmt->bind_param("i", $restaurant_id);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Check Out</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-success"><i class="bi bi-box-arrow-right"></i> Check Out & Manage Bookings</h2>
        <a href="view_branch.php" class="btn btn-secondary">Back</a>
    </div>

    <?php if($success_msg): ?><div class="alert alert-success"><?= $success_msg ?></div><?php endif; ?>
    <?php if($error_msg): ?><div class="alert alert-danger"><?= $error_msg ?></div><?php endif; ?>

    <?php if (empty($bookings)): ?>
        <div class="alert alert-info text-center py-5">
            <h5>No active bookings at the moment.</h5>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($bookings as $b): 
                $remaining = $b['total_amount'] - $b['advance_paid'];
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <span class="badge bg-<?= $b['status'] == 'checked_in' ? 'primary' : 'warning' ?>">
                                <?= ucfirst($b['status']) ?>
                            </span>
                            <small><?= $b['checkin_date'] ?> → <?= $b['checkout_date'] ?></small>
                        </div>
                        
                        <h5 class="mt-3"><?= htmlspecialchars($b['guest_name']) ?></h5>
                        <p class="text-muted small"><?= htmlspecialchars($b['mobile_number']) ?> | <?= htmlspecialchars($b['room_category']) ?></p>
                        
                        <div class="mt-3 pt-3 border-top">
                            <div><strong>Total:</strong> Rs. <?= number_format($b['total_amount'], 2) ?></div>
                            <div><strong>Paid:</strong> Rs. <?= number_format($b['advance_paid'], 2) ?></div>
                            <div class="text-danger"><strong>Remaining:</strong> Rs. <?= number_format($remaining, 2) ?></div>
                        </div>

                        <div class="mt-4 d-flex gap-2">
                            <button class="btn btn-primary flex-fill" onclick="editBooking(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>)">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <button class="btn btn-success flex-fill" onclick="confirmCheckout(<?= $b['id'] ?>, '<?= addslashes($b['guest_name']) ?>')">
                                <i class="bi bi-box-arrow-right"></i> Check Out
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="booking_id" id="edit_booking_id">
            <input type="hidden" name="old_id_photo" id="old_id_photo">

            <div class="modal-header">
                <h5 class="modal-title">Edit Booking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>Guest Name</label>
                        <input type="text" name="guest_name" id="edit_guest_name" class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>Mobile Number</label>
                        <input type="text" name="mobile_number" id="edit_mobile_number" class="form-control" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>Package / Room Name</label>
                        <select name="room_category" id="edit_room_category" class="form-select" required>
                            <?php foreach ($all_packages as $p): ?>
                                <option value="<?= htmlspecialchars($p['package_name']) ?>">
                                    <?= htmlspecialchars($p['package_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>ID Type</label>
                        <select name="id_type" id="edit_id_type" class="form-select">
                            <option value="citizenship">Citizenship</option>
                            <option value="passport">Passport</option>
                            <option value="driving_license">Driving License</option>
                            <option value="national_id">National ID</option>
                        </select>
                    </div>
                </div>

                <!-- Other fields -->
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>ID Number</label>
                        <input type="text" name="id_number" id="edit_id_number" class="form-control">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>Email</label>
                        <input type="email" name="email" id="edit_email" class="form-control">
                    </div>
                </div>

                <div class="mb-3">
                    <label>New ID Photo (Optional)</label>
                    <input type="file" name="id_photo" class="form-control" accept="image/*">
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label>Check-in Date</label>
                        <input type="date" name="checkin_date" id="edit_checkin_date" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label>Check-out Date</label>
                        <input type="date" name="checkout_date" id="edit_checkout_date" class="form-control">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label>Status</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="confirmed">Confirmed</option>
                            <option value="checked_in">Checked In</option>
                            <option value="checked_out">Checked Out</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>Total Amount (Rs)</label>
                        <input type="number" name="total_amount" id="edit_total_amount" class="form-control" step="0.01" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>Advance Paid (Rs)</label>
                        <input type="number" name="advance_paid" id="edit_advance_paid" class="form-control" step="0.01">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="update_booking" class="btn btn-primary">Update Booking</button>
            </div>
        </form>
    </div>
</div>

<!-- Checkout Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Check Out</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Mark <strong id="guestName"></strong> as Checked Out?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" id="checkoutForm" style="display:inline;">
                    <input type="hidden" name="booking_id" id="checkout_booking_id">
                    <button type="submit" name="checkout_booking" class="btn btn-success">Yes, Check Out</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editBooking(data) {
    document.getElementById('edit_booking_id').value = data.id;
    document.getElementById('edit_guest_name').value = data.guest_name || '';
    document.getElementById('edit_mobile_number').value = data.mobile_number || '';
    document.getElementById('edit_room_category').value = data.room_category || '';
    document.getElementById('edit_id_type').value = data.id_type || 'citizenship';
    document.getElementById('edit_id_number').value = data.id_number || '';
    document.getElementById('edit_email').value = data.email || '';
    document.getElementById('edit_checkin_date').value = data.checkin_date || '';
    document.getElementById('edit_checkout_date').value = data.checkout_date || '';
    document.getElementById('edit_total_amount').value = data.total_amount || '';
    document.getElementById('edit_advance_paid').value = data.advance_paid || '0';
    document.getElementById('edit_status').value = data.status || 'confirmed';
    document.getElementById('old_id_photo').value = data.id_photo || '';

    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function confirmCheckout(id, name) {
    document.getElementById('guestName').textContent = name;
    document.getElementById('checkout_booking_id').value = id;
    new bootstrap.Modal(document.getElementById('confirmModal')).show();
}
</script>

</body>
</html>