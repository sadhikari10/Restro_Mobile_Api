<?php
session_start();
if (empty($_SESSION['developer_logged_in'])) {
    header('Location: login.php');
    exit;
}
require '../Common/connection.php';

// Fetch only unreviewed issues
$issues = $conn->query("SELECT * FROM issues WHERE reviewed=0 ORDER BY submitted_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Issues</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="developer.css" rel="stylesheet">
    <style>
        body { padding-top: 70px; background:#f8f9fa; }
        .container { max-width:1000px; margin:auto; }
        .table th, .table td { vertical-align: middle; }
        .btn-solve { background:#28a745; color:#fff; border:none; }
        .btn-solve:hover { background:#218838; }
        #overlay {
            display:none; position:fixed; top:0; left:0; width:100%; height:100%;
            background:rgba(0,0,0,0.5); z-index:999;
        }
        #floatConfirm {
            display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
            background:#fff; padding:20px; border-radius:8px; z-index:1000; max-width:400px;
            box-shadow:0 0 15px rgba(0,0,0,0.3);
        }
        #floatConfirm button { margin-left:5px; }
        #floatConfirm .close-btn { background:none; border:none; font-size:20px; cursor:pointer; }
    </style>
</head>
<body>
<?php include('navbar.php'); ?>

<div class="container mt-4">
    <h2>Unreviewed Issues</h2>
    <button class="btn btn-secondary mb-3" onclick="window.location.href='dashboard.php'">Back to Dashboard</button>
    <?php if($issues->num_rows>0): ?>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Message</th>
                    <th>File</th>
                    <th>Submitted At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $issues->fetch_assoc()): ?>
                    <tr id="row-<?= $row['id'] ?>">
                        <td><?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['email']) ?></td>
                        <td><?= htmlspecialchars($row['message']) ?></td>
                        <td>
                            <?php if($row['file']): ?>
                                <a href="../uploads/issues/<?= htmlspecialchars($row['file']) ?>" target="_blank">View File</a>
                            <?php endif; ?>
                        </td>
                        <td><?= $row['submitted_at'] ?></td>
                        <td>
                            <button class="btn btn-solve btn-sm" onclick="openConfirm(<?= $row['id'] ?>)">Mark Solved</button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="alert alert-info">No unreviewed issues found.</div>
    <?php endif; ?>
</div>

<!-- Floating confirmation popup -->
<div id="overlay"></div>
<div id="floatConfirm">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <p style="margin:0;">Mark this issue as solved?</p>
        <button class="close-btn" id="closePopup">&times;</button>
    </div>
    <div style="margin-top:15px; text-align:right;">
        <button id="confirmSolve" class="btn btn-success">Yes</button>
        <button id="cancelSolve" class="btn btn-secondary">Cancel</button>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
let selectedId = 0;

function openConfirm(id){
    selectedId = id;
    $('#overlay, #floatConfirm').show();
}

$('#closePopup, #cancelSolve').click(function(){
    $('#overlay, #floatConfirm').hide();
});

$('#confirmSolve').click(function(){
    $.post('mark_solved.php', {id:selectedId,type:'issue'}, function(data){
        if(data.success){
            $('#row-'+selectedId).remove();
            $('#overlay, #floatConfirm').hide();
        } else {
            alert('Error: '+(data.msg || ''));
        }
    },'json');
});
</script>
</body>
</html>
