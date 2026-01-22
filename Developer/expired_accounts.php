<?php
session_start();

if (empty($_SESSION['developer_logged_in'])) {
    header('Location: login.php');
    exit;
}

$developerName = $_SESSION['developer_name'];
require '../Common/connection.php';
require '../Common/nepali_date.php';

// ────────────────────────────────────────────────
// AJAX - Load expired accounts table
// ────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    $search = trim($_GET['search'] ?? '');

    // Current date in BS
    $today_bs = ad_to_bs(date('Y-m-d'));
    $current_year = substr($today_bs, 0, 4);

    $sql = "
        SELECT 
            r.id AS restaurant_id,
            r.name AS restaurant_name,
            r.expiry_date,
            COALESCE(s.username, '—') AS superadmin_name,
            COALESCE(s.phone, '—')    AS superadmin_phone
        FROM restaurants r
        LEFT JOIN chains c ON r.chain_id = c.id
        LEFT JOIN users s 
            ON c.id = s.chain_id 
            AND s.role = 'superadmin'
        WHERE r.expiry_date < ?
    ";

    $params = [$today_bs];
    $types = "s";

    if ($search !== '') {
        $term = "%$search%";
        $sql .= " AND (
            r.name LIKE ? OR 
            COALESCE(s.username, '') LIKE ? OR 
            COALESCE(s.phone, '') LIKE ?
        )";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $types .= "sss";
    }

    $sql .= " ORDER BY r.expiry_date DESC, r.id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo "<tr><td colspan='5' class='text-center text-danger'>Database error</td></tr>";
        $conn->close();
        exit;
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $output = '';

    while ($row = $result->fetch_assoc()) {
        $expiry = $row['expiry_date'] ?? '—';

        // Already expired by the WHERE clause, but double-check for safety
        $show = true;

        if ($expiry !== '—') {
            $y = substr($expiry, 0, 4);
            if ($y == $current_year && $expiry >= $today_bs) {
                $show = false; // edge case safety
            }
        }

        if ($show) {
            $output .= "<tr>
                <td>" . htmlspecialchars($row['restaurant_name']) . "</td>
                <td>" . htmlspecialchars($row['superadmin_name']) . "</td>
                <td>" . htmlspecialchars($row['superadmin_phone']) . "</td>
                <td>" . htmlspecialchars($expiry) . "</td>
                <td>
                    <button class='btn btn-success btn-sm renew'
                        data-restaurant-id='{$row['restaurant_id']}'
                        data-restaurant-name='" . htmlspecialchars($row['restaurant_name'], ENT_QUOTES) . "'>
                        Renew
                    </button>
                </td>
            </tr>";
        }
    }

    if ($output === '') {
        $output = "<tr><td colspan='5' class='text-center'>No expired accounts found.</td></tr>";
    }

    echo $output;

    $stmt->close();
    $conn->close();
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Expired Accounts</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="developer.css" rel="stylesheet">
<style>
    body { padding-top: 70px; background: #f8f9fa; }
    .table-responsive thead th {
        position: sticky;
        top: 0;
        background: #343a40;
        color: white;
        z-index: 10;
    }
    @media (max-width: 576px) { .table { min-width: 700px; } }
</style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2 class="mb-4">Expired Accounts</h2>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <input type="text" id="searchInput" class="form-control" 
                   placeholder="Search restaurant / owner / phone...">
        </div>
        <div class="col-md-2">
            <button id="searchBtn" class="btn btn-primary w-100">Search</button>
        </div>
        <div class="col-md-2">
            <button id="clearBtn" class="btn btn-outline-secondary w-100">Clear</button>
        </div>
    </div>

    <div class="table-responsive shadow">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Restaurant Name</th>
                    <th>Owner (Superadmin)</th>
                    <th>Phone Number</th>
                    <th>Expiry Date (BS)</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="tableBody"></tbody>
        </table>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title" id="confirmModalLabel">Confirm Renewal</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="confirmBody">
        Are you sure you want to renew this restaurant? This will extend the expiry date by one year.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirmBtn">Confirm Renewal</button>
      </div>
    </div>
  </div>
</div>

<!-- Success Modal -->
<div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="successModalLabel">Success</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center py-4">
        <i class="bi bi-check-circle-fill fs-1"></i>
        <p class="mt-3 fw-bold">Restaurant renewed successfully!</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-success" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function loadAccounts(search = '') {
    $.get('', { ajax: 1, search }, function(html) {
        $('#tableBody').html(html);
    }).fail(function() {
        $('#tableBody').html('<tr><td colspan="5" class="text-center text-danger py-4">Failed to load data</td></tr>');
    });
}

$(document).ready(function() {
    loadAccounts(); // Initial load

    $('#searchBtn').on('click', function() {
        loadAccounts($('#searchInput').val().trim());
    });

    $('#clearBtn').on('click', function() {
        $('#searchInput').val('');
        loadAccounts();
    });

    let selectedId = 0;
    let selectedName = '';

    // Open confirmation
    $(document).on('click', '.renew', function() {
        selectedId = $(this).data('restaurant-id');
        selectedName = $(this).data('restaurant-name');

        $('#confirmBody').html(`
            <strong>Restaurant:</strong> ${selectedName}<br><br>
            Are you sure you want to renew this expired account?<br>
            This will extend expiry by <strong>one year</strong>.
        `);
        $('#confirmModal').modal('show');
    });

    // Confirm renewal
    $('#confirmBtn').on('click', function() {
        if (!selectedId) return;

        $('#confirmBtn').prop('disabled', true).text('Renewing...');
        $('#confirmModal').modal('hide');

        $.post('mark_renew.php', { restaurant_id: selectedId }, function(response) {
            if (response.success) {
                $('#successModal').modal('show');
                loadAccounts($('#searchInput').val().trim());
            } else {
                alert('Failed to renew: ' + (response.msg || 'Unknown error'));
            }
        }, 'json')
        .fail(function() {
            alert('Network error. Please try again.');
        })
        .always(function() {
            $('#confirmBtn').prop('disabled', false).text('Confirm Renewal');
            selectedId = 0;
        });
    });
});
</script>
</body>
</html>