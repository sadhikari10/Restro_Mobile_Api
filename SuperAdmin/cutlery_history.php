<?php
// SuperAdmin/cutlery_history.php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';

if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'superadmin' || empty($_SESSION['current_restaurant_id'])) {
    header('Location: ../login.php');
    exit;
}

$restaurant_id = $_SESSION['current_restaurant_id'];
$cutlery_id = isset($_GET['cutlery_id']) ? (int)$_GET['cutlery_id'] : 0;
$item_name  = isset($_GET['item']) ? htmlspecialchars($_GET['item']) : 'Item';

$stmt = $conn->prepare("SELECT item_name, category, current_stock FROM cutlery_inventory WHERE id = ? AND restaurant_id = ?");
$stmt->bind_param("ii", $cutlery_id, $restaurant_id);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) {
    header("Location: cutlery_inventory.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT h.*, u.username 
    FROM cutlery_history h 
    LEFT JOIN users u ON h.changed_by = u.id 
    WHERE h.cutlery_id = ? AND h.restaurant_id = ? 
    ORDER BY h.id DESC
");
$stmt->bind_param("ii", $cutlery_id, $restaurant_id);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>History - <?= $item_name ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold text-success"><i class="bi bi-clock-history me-2"></i>History - <?= $item_name ?></h4>
            <p class="text-muted">Current Stock: <strong><?= $item['current_stock'] ?></strong></p>
        </div>
        <div>
            <a href="cutlery_inventory.php" class="btn btn-secondary me-2">← Back</a>
            <button onclick="exportHistory()" class="btn btn-success">Export History</button>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover" id="historyTable">
                <thead class="table-light">
                    <tr>
                        <th>Date & Time</th>
                        <th>Action</th>
                        <th class="text-center">Qty Change</th>
                        <th class="text-center">Stock After</th>
                        <th>Remarks</th>
                        <th>Done By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $row): 
                        $isPositive = in_array($row['action'], ['added', 'restocked']);
                        $sign = $isPositive ? '+' : '-';
                        $badge = $isPositive ? 'bg-success' : 'bg-danger';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['created_at']) ?></td>
                        <td><span class="badge <?= $badge ?>"><?= strtoupper($row['action']) ?></span></td>
                        <td class="text-center fw-bold <?= $isPositive ? 'text-success' : 'text-danger' ?>">
                            <?= $sign . $row['quantity'] ?>
                        </td>
                        <td class="text-center fw-bold"><?= $row['stock_after'] ?? '-' ?></td>
                        <td><?= htmlspecialchars($row['remarks'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['username'] ?? 'System') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function exportHistory() {
    const table = document.getElementById('historyTable');
    let csv = "Date & Time,Action,Quantity Change,Stock After,Remarks,Done By\n";
    
    table.querySelectorAll('tbody tr').forEach(row => {
        const cells = row.querySelectorAll('td');
        csv += `"${cells[0].innerText}","${cells[1].innerText.replace(/<[^>]*>/g,'')}","${cells[2].innerText}",${cells[3].innerText},"${cells[4].innerText.replace(/"/g,'""')}","${cells[5].innerText}"\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'cutlery_history_<?= date('Y-m-d') ?>.csv';
    link.click();
}
</script>
</body>
</html>