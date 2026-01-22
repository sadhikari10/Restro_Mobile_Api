<?php
session_start();

if (empty($_SESSION['developer_logged_in'])) {
    header('Location: login.php');
    exit;
}

require '../Common/connection.php';
require '../Common/nepali_date.php';

$developerName = $_SESSION['developer_name'];

// ────────────────────────────────────────────────
// AJAX - Load running restaurants table
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
    ";

    $params = [];
    $types = "";

    if ($search !== '') {
        $term = "%$search%";
        $sql .= " WHERE (
            r.name LIKE ? OR 
            COALESCE(s.username, '') LIKE ? OR 
            COALESCE(s.phone, '') LIKE ?
        )";
        $params = [$term, $term, $term];
        $types = "sss";
    }

    $sql .= " ORDER BY r.id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo "<tr><td colspan='5' class='text-center text-danger'>Database error</td></tr>";
        $conn->close();
        exit;
    }

    if ($types) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $output = '';

    while ($row = $result->fetch_assoc()) {
        $expiry = $row['expiry_date'] ?? '';

        $is_running = false;

        if ($expiry) {
            $y = substr($expiry, 0, 4);

            if ($y > $current_year || ($y == $current_year && $expiry >= $today_bs)) {
                $is_running = true;
            }
        }

        if ($is_running) {
            $output .= "<tr>
                <td>" . htmlspecialchars($row['restaurant_name']) . "</td>
                <td>" . htmlspecialchars($row['superadmin_name']) . "</td>
                <td>" . htmlspecialchars($row['superadmin_phone']) . "</td>
                <td>" . ($expiry ? htmlspecialchars($expiry) : '—') . "</td>
                <td>
                    <button class='btn btn-primary btn-sm renew-btn'
                        data-id='{$row['restaurant_id']}'
                        data-name='" . htmlspecialchars($row['restaurant_name'], ENT_QUOTES) . "'
                        data-owner='" . htmlspecialchars($row['superadmin_name'], ENT_QUOTES) . "'
                        data-phone='" . htmlspecialchars($row['superadmin_phone'], ENT_QUOTES) . "'>
                        Renew
                    </button>
                </td>
            </tr>";
        }
    }

    if ($output === '') {
        $output = "<tr><td colspan='5' class='text-center'>No running restaurants found.</td></tr>";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Running Restaurants</title>
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
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h2 class="mb-4">Running Restaurants</h2>

    <div class="row g-3 mb-4">
        <div class="col-md-5 col-lg-4">
            <input type="text" id="searchInput" class="form-control" 
                   placeholder="Search restaurant / owner / phone...">
        </div>
        <div class="col-md-3 col-lg-2">
            <button id="searchBtn" class="btn btn-primary w-100">Search</button>
        </div>
        <div class="col-md-3 col-lg-2">
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
            <tbody id="tableBody">
                <!-- Filled by AJAX -->
            </tbody>
        </table>
    </div>
</div>

<!-- Simple confirmation overlay -->
<div id="overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;"></div>
<div id="confirmPopup" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:24px;border-radius:8px;z-index:10000;max-width:420px;box-shadow:0 8px 30px rgba(0,0,0,0.25);">
    <h5 class="mb-3" id="confirmText"></h5>
    <div class="text-end">
        <button class="btn btn-outline-secondary me-2" id="cancelBtn">Cancel</button>
        <button class="btn btn-primary" id="confirmBtn">Renew</button>
    </div>
</div>

<div class="alert alert-success position-fixed top-0 start-50 translate-middle-x mt-4" id="successAlert" role="alert" style="display:none;z-index:10001;">
    Successfully renewed!
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function loadTable(search = '') {
    $.get('', { ajax: 1, search }, function(html) {
        $('#tableBody').html(html);
    }).fail(function() {
        $('#tableBody').html('<tr><td colspan="5" class="text-center text-danger py-4">Failed to load data</td></tr>');
    });
}

$(function() {
    loadTable();

    $('#searchBtn').click(() => loadTable($('#searchInput').val().trim()));
    $('#clearBtn').click(() => { $('#searchInput').val(''); loadTable(); });

    let selectedId = null;

    $(document).on('click', '.renew-btn', function() {
        selectedId = $(this).data('id');
        const name  = $(this).data('name');
        const owner = $(this).data('owner') || '—';
        const phone = $(this).data('phone') || '—';

        $('#confirmText').text(`Renew "${name}"?\n\nOwner: ${owner}\nPhone: ${phone}`);
        $('#overlay, #confirmPopup').show();
    });

    $('#cancelBtn, #overlay').click(() => {
        $('#overlay, #confirmPopup').hide();
        selectedId = null;
    });

    $('#confirmBtn').click(function() {
        if (!selectedId) return;

        const btn = $(this).prop('disabled', true).text('Processing...');

        $.post('mark_renew.php', { restaurant_id: selectedId })
            .done(function(res) {
                if (res.success) {
                    $('#overlay, #confirmPopup').hide();
                    $('#successAlert').fadeIn().delay(2800).fadeOut();
                    loadTable($('#searchInput').val().trim());
                } else {
                    alert('Failed: ' + (res.msg || 'Unknown error'));
                }
            })
            .fail(() => alert('Request failed'))
            .always(() => {
                btn.prop('disabled', false).text('Renew');
                selectedId = null;
            });
    });
});
</script>
</body>
</html>