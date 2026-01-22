<?php 
session_start(); 
if (empty($_SESSION['developer_logged_in'])) {
    header('Location: login.php'); 
    exit; 
}
$developerName = $_SESSION['developer_name']; 
require '../Common/connection.php'; 

// Handle AJAX request
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    $search = $_GET['search'] ?? '';

    $sql = "SELECT ph.id, r.name AS restaurant_name, u.username, u.phone, 
                   ph.old_expiry_date, ph.new_expiry_date, ph.created_at
            FROM payment_history ph
            INNER JOIN restaurants r ON ph.restaurant_id = r.id
            INNER JOIN users u ON r.id = u.restaurant_id AND u.role = 'admin'";

    if ($search !== '') {
        $term = "%$search%";
        $sql .= " WHERE (u.username LIKE ? OR r.name LIKE ? OR u.phone LIKE ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $term, $term, $term);
    } else {
        $stmt = $conn->prepare($sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $old_bs = $row['old_expiry_date'];     // e.g. 2081-07-15
            $new_bs = $row['new_expiry_date'];     // e.g. 2082-07-15
            $created_full = $row['created_at'];    // e.g. 2081-07-20 14:30:25

            // Format expiry dates (date only)
            $old_disp = format_nepali_date($old_bs);
            $new_disp = format_nepali_date($new_bs);

            // Format created_at: date with month name + time
            $created_disp = format_nepali_datetime($created_full);

            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['restaurant_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['username']) . "</td>";
            echo "<td>" . htmlspecialchars($row['phone']) . "</td>";
            echo "<td>" . $old_disp . "</td>";
            echo "<td>" . $new_disp . "</td>";
            echo "<td>" . $created_disp . "</td>";
            echo "</tr>";
        }
    } else {
        echo '<tr><td colspan="6" class="text-center">No payment history found.</td></tr>';
    }

    $stmt->close();
    $conn->close();
    exit;
}

// Helper: BS date → "15 Shrawan 2081"
function format_nepali_date($bs_date) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bs_date, $m)) {
        return htmlspecialchars($bs_date);
    }
    [, $y, $m, $d] = $m;
    $m = ltrim($m, '0'); // convert '07' → 7
    $nepali_months = [
        1 => 'Baisakh', 2 => 'Jestha', 3 => 'Ashadh', 4 => 'Shrawan',
        5 => 'Bhadra', 6 => 'Ashwin', 7 => 'Kartik', 8 => 'Mangsir',
        9 => 'Poush', 10 => 'Magh', 11 => 'Falgun', 12 => 'Chaitra'
    ];
    return "$d " . ($nepali_months[$m] ?? 'Unknown') . " $y";
}

// Helper: BS datetime → "20 Shrawan 2081 14:30:25"
function format_nepali_datetime($bs_datetime) {
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})$/', $bs_datetime, $m)) {
        return htmlspecialchars($bs_datetime);
    }
    $date_part = $m[1]; // 2081-07-20
    $time_part = $m[2]; // 14:30:25
    return format_nepali_date($date_part) . " $time_part";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payment History</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="developer.css" rel="stylesheet">
<style>
.table-responsive thead th { position: sticky; top: 0; background:#343a40; color:#fff; z-index:10; }
body { padding-top: 70px; }
@media (max-width:576px) { .table { min-width:700px; } }
</style>
</head>
<body>
<?php include('navbar.php'); ?>

<div class="container mt-4">
    <h2 class="mb-4">Payment History</h2>

    <div class="row mb-3">
        <div class="col-md-4">
            <input type="text" id="searchInput" class="form-control" placeholder="Search by restaurant, owner or phone">
        </div>
        <div class="col-md-2">
            <button id="searchBtn" class="btn btn-primary w-100">Search</button>
        </div>
        <div class="col-md-2">
            <button id="clearBtn" class="btn btn-secondary w-100">Clear</button>
        </div>
    </div>

    <div class="table-responsive shadow-sm">
        <table class="table table-bordered table-hover align-middle" id="historyTable">
            <thead class="table-dark">
                <tr>
                    <th>Restaurant</th>
                    <th>Owner</th>
                    <th>Phone</th>
                    <th>Old Expiry</th>
                    <th>New Expiry</th>
                    <th>Renewed On</th>
                </tr>
            </thead>
            <tbody id="tableBody"></tbody>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
function loadHistory(search = '') {
    $.get('', { ajax: 1, search: search }, function(html) {
        $('#tableBody').html(html);
    });
}
$(function() {
    loadHistory();
    $('#searchBtn').on('click', () => loadHistory($('#searchInput').val()));
    $('#clearBtn').on('click', () => { $('#searchInput').val(''); loadHistory(); });
});
</script>
</body>
</html>