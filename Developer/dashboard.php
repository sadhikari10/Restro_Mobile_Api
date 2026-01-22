<?php
session_start();

// Check if developer is logged in
if (empty($_SESSION['developer_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Get developer name from session
$developerName = $_SESSION['developer_name'];

require '../Common/connection.php';
require '../Common/nepali_date.php';  // ← This line was missing - now added!

// Helper function: Format BS date to "20 Shrawan 2081" style
function format_nepali_date($bs_date) {
    // Remove time part if present
    $bs_date = preg_replace('/\s.*/', '', $bs_date);

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bs_date, $m)) {
        return htmlspecialchars($bs_date); // fallback
    }

    [, $year, $month, $day] = $m;

    $nepali_months = [
        '01' => 'Baisakh', '02' => 'Jestha',  '03' => 'Ashadh', '04' => 'Shrawan',
        '05' => 'Bhadra',  '06' => 'Ashwin', '07' => 'Kartik', '08' => 'Mangsir',
        '09' => 'Poush',   '10' => 'Magh',   '11' => 'Falgun', '12' => 'Chaitra'
    ];

    $month_name = $nepali_months[$month] ?? 'Unknown';
    return "$day $month_name $year";
}

// Fetch dashboard stats
$total_restaurants = $conn->query("SELECT COUNT(*) AS count FROM restaurants")->fetch_assoc()['count'];

$trial_accounts = $conn->query("SELECT COUNT(*) AS count FROM restaurants WHERE is_trial = 1")->fetch_assoc()['count'];

// Use BS today for correct comparison
$today_bs = ad_to_bs(date('Y-m-d'));

$running_restaurants = $conn->query("SELECT COUNT(*) AS count FROM restaurants WHERE expiry_date >= '$today_bs'")->fetch_assoc()['count'];

$expired_accounts = $conn->query("SELECT COUNT(*) AS count FROM restaurants WHERE expiry_date < '$today_bs'")->fetch_assoc()['count'];

// Recent Payments (last 5)
$recent_payments_sql = "
    SELECT r.name AS restaurant_name, 
           ph.new_expiry_date, 
           ph.created_at 
    FROM payment_history ph 
    INNER JOIN restaurants r ON ph.restaurant_id = r.id 
    ORDER BY ph.created_at DESC 
    LIMIT 5
";
$recent_payments_result = $conn->query($recent_payments_sql);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="developer.css" rel="stylesheet">
    <style>
        body { padding-top: 70px; background-color: #f8f9fa; }
        .welcome-header { text-align: center; margin-bottom: 40px; }
        .stat-card { 
            border: none; 
            border-radius: 12px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.08); 
            transition: transform 0.25s; 
        }
        .stat-card:hover { transform: translateY(-6px); }
        .stat-icon { font-size: 2.4rem; margin-bottom: 12px; }
        .recent-payments { margin-top: 50px; }
        .table { border-radius: 10px; overflow: hidden; }
    </style>
</head>
<body>
    <?php include('navbar.php'); ?>

    <div class="container mt-4">
        <!-- Welcome Message -->
        <div class="welcome-header">
            <h1 class="display-4 text-primary fw-bold">Welcome, <?php echo htmlspecialchars($developerName); ?>!</h1>
            <p class="lead text-muted">Your Developer Dashboard Overview</p>
        </div>

        <!-- Stats Cards -->
        <div class="row g-4">
            <div class="col-md-3">
                <div class="card stat-card bg-primary text-white text-center p-4">
                    <div class="card-body">
                        <i class="bi bi-building stat-icon"></i>
                        <h5 class="card-title">Total Restaurants</h5>
                        <h2 class="card-text"><?php echo $total_restaurants; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-warning text-white text-center p-4">
                    <div class="card-body">
                        <i class="bi bi-hourglass-split stat-icon"></i>
                        <h5 class="card-title">Trial Accounts</h5>
                        <h2 class="card-text"><?php echo $trial_accounts; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-success text-white text-center p-4">
                    <div class="card-body">
                        <i class="bi bi-check-circle stat-icon"></i>
                        <h5 class="card-title">Running Restaurants</h5>
                        <h2 class="card-text"><?php echo $running_restaurants; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card bg-danger text-white text-center p-4">
                    <div class="card-body">
                        <i class="bi bi-exclamation-triangle stat-icon"></i>
                        <h5 class="card-title">Expired Accounts</h5>
                        <h2 class="card-text"><?php echo $expired_accounts; ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center mt-5 mb-4">
            <a href="view_suggestions.php" class="btn btn-success btn-lg mx-2 px-4">View Suggestions</a>
            <a href="view_issues.php" class="btn btn-danger btn-lg mx-2 px-4">View Issues</a>
        </div>

        <!-- Recent Payments -->
        <div class="recent-payments">
            <h3 class="mb-4">Recent Payments</h3>
            <div class="table-responsive shadow-sm">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Restaurant Name</th>
                            <th>New Expiry Date (BS)</th>
                            <th>Payment Date (BS)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_payments_result->num_rows > 0): ?>
                            <?php while ($row = $recent_payments_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['restaurant_name']); ?></td>
                                    <td><?php echo format_nepali_date($row['new_expiry_date']); ?></td>
                                    <td><?php 
                                        // Remove time part from created_at
                                        $pay_date = preg_replace('/\s.*/', '', $row['created_at']);
                                        echo format_nepali_date($pay_date); 
                                    ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text-center py-4 text-muted">No recent payments found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.js"></script>
</body>
</html>