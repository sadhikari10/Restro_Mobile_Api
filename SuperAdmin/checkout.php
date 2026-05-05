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
                WHERE package_name = ? AND restaurant_id = ?");
            $update_pkg->bind_param("si", $booking['room_category'], $restaurant_id);
            $update_pkg->execute();
            $update_pkg->close();

            $success_msg = "Guest checked out successfully! Package is now available.";
        } else {
            $error_msg = "Checkout failed.";
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
        <h2 class="fw-bold text-success"><i class="bi bi-box-arrow-right"></i> Guest Check Out</h2>
        <a href="view_branch.php" class="btn btn-secondary">Back</a>
    </div>

    <?php if($success_msg): ?><div class="alert alert-success"><?= $success_msg ?></div><?php endif; ?>
    <?php if($error_msg): ?><div class="alert alert-danger"><?= $error_msg ?></div><?php endif; ?>

    <?php if (empty($bookings)): ?>
        <div class="alert alert-info text-center py-5">
            <h5>No active bookings for check-out at the moment.</h5>
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
                            <small class="text-muted"><?= $b['checkin_date'] ?> → <?= $b['checkout_date'] ?></small>
                        </div>
                        
                        <h5 class="mt-3"><?= htmlspecialchars($b['guest_name']) ?></h5>
                        <p class="text-muted small"><?= htmlspecialchars($b['mobile_number']) ?></p>
                        <p><strong>Room:</strong> <?= htmlspecialchars($b['room_category']) ?></p>
                        
                        <div class="mt-3 pt-3 border-top">
                            <div class="d-flex justify-content-between">
                                <span>Total Amount:</span>
                                <strong>Rs. <?= number_format($b['total_amount'], 2) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Advance Paid:</span>
                                <strong>Rs. <?= number_format($b['advance_paid'], 2) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between text-danger">
                                <span><strong>Remaining:</strong></span>
                                <strong>Rs. <?= number_format($remaining, 2) ?></strong>
                            </div>
                        </div>

                        <button class="btn btn-success w-100 mt-4" 
                                onclick="confirmCheckout(<?= $b['id'] ?>, '<?= addslashes($b['guest_name']) ?>')">
                            <i class="bi bi-box-arrow-right"></i> Check Out Guest
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Centered Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Check Out</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Mark <strong id="guestName"></strong> as Checked Out?</p>
                <p class="text-muted small">This will make the room/package available again.</p>
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
function confirmCheckout(id, name) {
    document.getElementById('guestName').textContent = name;
    document.getElementById('checkout_booking_id').value = id;
    new bootstrap.Modal(document.getElementById('confirmModal')).show();
}
</script>

</body>
</html>