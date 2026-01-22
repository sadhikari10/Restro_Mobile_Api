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

    $sql = "
        SELECT 
            r.id AS restaurant_id, 
            r.name AS restaurant_name, 
            r.created_at, 
            r.expiry_date,
            COALESCE(s.username, '—') AS username,
            COALESCE(s.phone, '—') AS phone
        FROM restaurants r
        LEFT JOIN chains c ON r.chain_id = c.id
        LEFT JOIN users s 
            ON c.id = s.chain_id 
            AND s.role = 'superadmin'
        WHERE r.is_trial = 1
    ";

    $params = [];
    $types = "";

    if ($search !== '') {
        $term = "%$search%";
        $sql .= " AND (
            COALESCE(s.username, '') LIKE ? OR 
            r.name LIKE ? OR 
            COALESCE(s.phone, '') LIKE ?
        )";
        $params = [$term, $term, $term];
        $types = "sss";
    }

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo '<tr><td colspan="6" class="text-center text-danger">SQL Error</td></tr>';
        $conn->close();
        exit;
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Extract date part only from created_at
            $created_full = $row['created_at'];
            $created_bs = preg_replace('/\s.*/', '', $created_full);

            $expiry_bs = $row['expiry_date'];

            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['username']) . "</td>";
            echo "<td>" . htmlspecialchars($row['restaurant_name']) . "</td>";
            echo "<td>" . format_nepali_date($created_bs) . "</td>";
            echo "<td>" . format_nepali_date($expiry_bs) . "</td>";
            echo "<td>" . htmlspecialchars($row['phone']) . "</td>";
            echo "<td>
                <button class='btn btn-success btn-sm mark-paid'
                    data-id='" . $row['restaurant_id'] . "'
                    data-name='" . htmlspecialchars($row['restaurant_name']) . "'>
                    Mark Paid
                </button>
            </td>";
            echo "</tr>";
        }
    } else {
        echo '<tr><td colspan="6" class="text-center">No trial accounts found.</td></tr>';
    }

    $stmt->close();
    $conn->close();
    exit;
}

// Helper: Format BS date → "20 Shrawan 2081"
function format_nepali_date($bs_date) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bs_date, $m)) {
        return htmlspecialchars($bs_date);
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Trial Accounts</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="developer.css" rel="stylesheet">
<style>
    .table-responsive thead th { position: sticky; top: 0; background:#343a40; color:#fff; z-index:10; }
    body { padding-top: 70px; }
    @media (max-width:576px) { .table { min-width:700px; } }
</style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2 class="mb-4">Trial Accounts</h2>

    <div class="row mb-3">
        <div class="col-md-4">
            <input type="text" id="searchInput" class="form-control" placeholder="Search by superadmin, restaurant or phone">
        </div>
        <div class="col-md-2">
            <button id="searchBtn" class="btn btn-primary w-100">Search</button>
        </div>
        <div class="col-md-2">
            <button id="clearBtn" class="btn btn-secondary w-100">Clear</button>
        </div>
    </div>

    <div class="table-responsive shadow-sm">
        <table class="table table-bordered table-hover align-middle" id="trialTable">
            <thead class="table-dark">
                <tr>
                    <th>Superadmin</th>
                    <th>Restaurant</th>
                    <th>Created</th>
                    <th>Expiry</th>
                    <th>Phone</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="tableBody"></tbody>
        </table>
    </div>
</div>

<!-- Confirm Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Mark as Paid</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="confirmBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmBtn">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- Success Modal -->
<div class="modal fade" id="successModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Success</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">Marked as paid and extended by 1 Nepali year!</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function loadAccounts(search = '') {
    $.get('', { ajax: 1, search: search }, function(html) {
        $('#tableBody').html(html);
    }).fail(function() {
        $('#tableBody').html('<tr><td colspan="6" class="text-center text-danger">Failed to load data</td></tr>');
    });
}

$(function() {
    loadAccounts();

    $('#searchBtn').on('click', () => loadAccounts($('#searchInput').val()));
    $('#clearBtn').on('click', () => { $('#searchInput').val(''); loadAccounts(); });

    let selectedId = 0;
    let selectedName = '';

    $(document).on('click', '.mark-paid', function() {
        selectedId = $(this).data('id');
        selectedName = $(this).data('name');
        $('#confirmBody').text(`Mark "${selectedName}" as paid? This will extend expiry by 1 Nepali year.`);
        $('#confirmModal').modal('show');
    });

    $('#confirmBtn').on('click', function() {
        if (!selectedId) return;

        const $btn = $(this).prop('disabled', true).text('Processing...');

        $.post('mark_paid.php', { restaurant_id: selectedId })
            .done(function(res) {
                if (res.success) {
                    $('#confirmModal').modal('hide');
                    $('#successModal').modal('show');
                    loadAccounts($('#searchInput').val());
                } else {
                    alert('Error: ' + (res.msg || 'Unknown'));
                }
            })
            .fail(function() {
                alert('Request failed.');
            })
            .always(function() {
                $btn.prop('disabled', false).text('Confirm');
                selectedId = 0;
            });
    });
});
</script>
</body>
</html>