<?php
// order_history.php - With Professional Excel Export

session_start();
require '../vendor/autoload.php'; // Needed for PhpOffice\PhpSpreadsheet
require '../Common/connection.php';
require '../Common/nepali_date.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

$restaurant_id = $_SESSION['restaurant_id'];
$restaurant_name = $_SESSION['restaurant_name'] ?? 'Restaurant';

// Handle Clear Filter
if (isset($_POST['clear_filter'])) {
    unset($_SESSION['order_history_start_date'], $_SESSION['order_history_end_date']);
    header("Location: order_history.php");
    exit;
}

// Handle POST filter dates
$start_date_bs = $_POST['start_date'] ?? $_SESSION['order_history_start_date'] ?? '';
$end_date_bs   = $_POST['end_date'] ?? $_SESSION['order_history_end_date'] ?? '';

$_SESSION['order_history_start_date'] = $start_date_bs;
$_SESSION['order_history_end_date']   = $end_date_bs;

// ==================== EXCEL EXPORT ====================
if (isset($_POST['export_excel'])) {
    $conditions = "bc.restaurant_id = ? AND o.status = 'paid'";
    $params = [$restaurant_id];
    $types = "i";

    if ($start_date_bs && $end_date_bs) {
        $conditions .= " AND DATE(o.created_at) BETWEEN ? AND ?";
        $params[] = $start_date_bs;
        $params[] = $end_date_bs;
        $types .= "ss";
    } elseif ($start_date_bs) {
        $conditions .= " AND DATE(o.created_at) = ?";
        $params[] = $start_date_bs;
        $types .= "s";
    }

    $stmt = $conn->prepare("
        SELECT o.id, o.created_at AS order_date, o.table_number, o.items, o.order_by,
               bc.bill_printed_at AS printed_at, bc.net_total, bc.payment_method, bc.last_bill_number AS bill_number
        FROM orders o 
        JOIN bill_counter bc ON o.id = bc.order_id 
        WHERE $conditions 
        ORDER BY o.created_at DESC
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    // Collect unique order_by IDs
    $order_by_ids = [];
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['order_by'])) {
            $order_by_ids[$row['order_by']] = true;
        }
    }
    $result->data_seek(0);  // Reset pointer

    // Fetch usernames
    $usernames = [];
    if (!empty($order_by_ids)) {
        $ids = array_keys($order_by_ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt_users = $conn->prepare("SELECT id, username FROM users WHERE id IN ($placeholders)");
        $stmt_users->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt_users->execute();
        $res_users = $stmt_users->get_result();
        while ($u = $res_users->fetch_assoc()) {
            $usernames[$u['id']] = $u['username'];
        }
        $stmt_users->close();
    }

    // Clean restaurant name for filename
    $clean_name = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $restaurant_name);
    $clean_name = $clean_name ?: 'Restaurant';

    $filename = "Paid_Orders_{$clean_name}";
    if ($start_date_bs && $end_date_bs) {
        $filename .= "_{$start_date_bs}_to_{$end_date_bs}";
    }
    $filename .= ".xlsx";

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Title
    $sheet->setCellValue('A1', 'PAID ORDERS HISTORY');
    $sheet->mergeCells('A1:H1');
    $sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Restaurant & Period
    $sheet->setCellValue('A2', "Restaurant: {$restaurant_name}");
    $sheet->mergeCells('A2:D2');
    $sheet->getStyle('A2')->getFont()->setBold(true);

    $period = $start_date_bs && $end_date_bs ? "$start_date_bs to $end_date_bs (BS)" : "All Time";
    $sheet->setCellValue('E2', "Period: {$period}");
    $sheet->mergeCells('E2:H2');
    $sheet->getStyle('E2')->getFont()->setBold(true);

    // Headers
    $headers = ['Order Date (BS)', 'Bill Printed (BS)', 'Table/Customer/Phone', 'Items', 'Bill No', 'Net Total (Rs)', 'Payment Method', 'Entered By'];
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . '4', $h);
        $sheet->getStyle($col . '4')->getFont()->setBold(true);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }
    $sheet->getStyle('A4:H4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFD9EAD3');

    $rowNum = 5;
    $grandTotal = 0;

    while ($row = $result->fetch_assoc()) {
        // Table display
        $table_num = $row['table_number'] ?? '';
        if (preg_match('/^\d{7,10}$/', $table_num)) {
            $table_display = 'Phone: ' . $table_num;
        } elseif (is_numeric($table_num) && strlen($table_num) <= 3) {
            $table_display = 'Table: ' . $table_num;
        } else {
            $table_display = 'Customer: ' . $table_num ?: '—';
        }

        // Items
        $items_json = json_decode($row['items'] ?? '[]', true) ?? [];
        $item_names = [];
        if (!empty($items_json)) {
            $item_ids = array_column($items_json, 'item_id');
            if (!empty($item_ids)) {
                $placeholders = str_repeat('?,', count($item_ids) - 1) . '?';
                $stmt_item = $conn->prepare("SELECT id, item_name FROM menu_items WHERE id IN ($placeholders) AND restaurant_id = ?");
                $stmt_item->bind_param(str_repeat('i', count($item_ids)) . 'i', ...array_merge($item_ids, [$restaurant_id]));
                $stmt_item->execute();
                $res_items = $stmt_item->get_result();
                $menu_map = [];
                while ($item_row = $res_items->fetch_assoc()) {
                    $menu_map[$item_row['id']] = $item_row['item_name'];
                }
                $stmt_item->close();

                foreach ($items_json as $it) {
                    $name = $menu_map[$it['item_id'] ?? 0] ?? 'Unknown Item';
                    $qty = (int)($it['quantity'] ?? 0);
                    if ($qty > 1) $name .= " ×{$qty}";
                    $notes = $it['notes'] ?? '';
                    if ($notes) $name .= " ($notes)";
                    $item_names[] = $name;
                }
            }
        }
        $items_list = !empty($item_names) ? implode(', ', $item_names) : '—';

        $grandTotal += $row['net_total'];

        // Entered By
        $entered_by = isset($usernames[$row['order_by']]) ? $usernames[$row['order_by']] : 'Unknown';

        $sheet->setCellValue('A' . $rowNum, substr($row['order_date'], 0, 16));
        $sheet->setCellValue('B' . $rowNum, $row['printed_at'] ? substr($row['printed_at'], 0, 16) : '—');
        $sheet->setCellValue('C' . $rowNum, $table_display);
        $sheet->setCellValue('D' . $rowNum, $items_list);
        $sheet->setCellValue('E' . $rowNum, $row['bill_number'] ?? '—');
        $sheet->setCellValue('F' . $rowNum, number_format($row['net_total'], 2));
        $sheet->setCellValue('G' . $rowNum, ucfirst($row['payment_method'] ?? '—'));
        $sheet->setCellValue('H' . $rowNum, $entered_by);

        $rowNum++;
    }
    $stmt->close();

    // Grand Total
    $sheet->setCellValue('E' . $rowNum, 'Grand Total');
    $sheet->getStyle('E' . $rowNum)->getFont()->setBold(true);
    $sheet->setCellValue('F' . $rowNum, number_format($grandTotal, 2));
    $sheet->getStyle('F' . $rowNum)->getFont()->setBold(true);

    // Send file
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . urlencode($filename) . '"');
    header('Cache-Control: max-age=0');

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

// ==================== HTML PART ====================

// Fetch orders for display
$conditions = "bc.restaurant_id = ? AND o.status = 'paid'";
$params = [$restaurant_id];
$types = "i";

if ($start_date_bs && $end_date_bs) {
    $conditions .= " AND DATE(o.created_at) BETWEEN ? AND ?";
    $params[] = $start_date_bs;
    $params[] = $end_date_bs;
    $types .= "ss";
} elseif ($start_date_bs) {
    $conditions .= " AND DATE(o.created_at) BETWEEN ? AND ?";
    $params[] = $start_date_bs;
    $types .= "s";
}

$stmt = $conn->prepare("
    SELECT o.id, o.created_at AS order_date, o.table_number, o.items,
           bc.bill_printed_at AS printed_at, bc.net_total, bc.payment_method, bc.last_bill_number AS bill_number
    FROM orders o 
    JOIN bill_counter bc ON o.id = bc.order_id 
    WHERE $conditions 
    ORDER BY o.created_at DESC
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$orders = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Paid Orders History - <?= htmlspecialchars($restaurant_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .table { background: white; }
        .items-list { max-width: 400px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="card shadow-lg">
        <div class="card-header bg-primary text-white text-center">
            <h4 class="mb-0">Paid Orders History - <?= htmlspecialchars($restaurant_name) ?></h4>
        </div>
        <div class="card-body">
            <div class="mb-4 text-end">
                <a href="dashboard.php" class="btn btn-light border">&larr; Back</a>
            </div>

            <form method="POST" class="row mb-4">
                <div class="col-md-4">
                    <label class="form-label">Start Date (BS)</label>
                    <input type="text" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date_bs) ?>" placeholder="2082-12-01">
                </div>
                <div class="col-md-4">
                    <label class="form-label">End Date (BS)</label>
                    <input type="text" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date_bs) ?>" placeholder="2082-12-30">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary me-2">Filter</button>
                    <?php if ($start_date_bs || $end_date_bs): ?>
                        <button type="submit" name="clear_filter" value="1" class="btn btn-secondary me-2">Clear</button>
                    <?php endif; ?>
                    <button type="submit" name="export_excel" class="btn btn-success">Export Excel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle text-center">
            <thead class="table-dark">
                <tr>
                    <th>Order Date (BS)</th>
                    <th>Bill Printed (BS)</th>
                    <th>Table/Customer</th>
                    <th>Items</th>
                    <th>Bill No</th>
                    <th>Net Total (Rs)</th>
                    <th>Payment</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($orders)): ?>
                    <?php foreach ($orders as $row): ?>
                        <?php
                        $table_num = $row['table_number'] ?? '';
                        if (preg_match('/^\d{7,10}$/', $table_num)) {
                            $table_display = 'Phone: ' . $table_num;
                        } elseif (is_numeric($table_num) && strlen($table_num) <= 3) {
                            $table_display = 'Table: ' . $table_num;
                        } else {
                            $table_display = 'Customer: ' . ($table_num ?: '—');
                        }

                        $items_json = json_decode($row['items'] ?? '[]', true) ?? [];
                        $item_names = [];
                        if (!empty($items_json)) {
                            $item_ids = array_column($items_json, 'item_id');
                            if (!empty($item_ids)) {
                                $placeholders = str_repeat('?,', count($item_ids) - 1) . '?';
                                $stmt_item = $conn->prepare("SELECT id, item_name FROM menu_items WHERE id IN ($placeholders) AND restaurant_id = ?");
                                $stmt_item->bind_param(str_repeat('i', count($item_ids)) . 'i', ...array_merge($item_ids, [$restaurant_id]));
                                $stmt_item->execute();
                                $res_items = $stmt_item->get_result();
                                $menu_map = [];
                                while ($item_row = $res_items->fetch_assoc()) {
                                    $menu_map[$item_row['id']] = $item_row['item_name'];
                                }
                                $stmt_item->close();

                                foreach ($items_json as $it) {
                                    $name = $menu_map[$it['item_id'] ?? 0] ?? 'Unknown';
                                    $qty = (int)($it['quantity'] ?? 0);
                                    if ($qty > 1) $name .= " ×{$qty}";
                                    $notes = $it['notes'] ?? '';
                                    if ($notes) $name .= " ($notes)";
                                    $item_names[] = $name;
                                }
                            }
                        }
                        $items_list = !empty($item_names) ? implode(', ', $item_names) : '—';

                        $order_date_bs = substr($row['order_date'], 0, 16);
                        $printed_at_bs = $row['printed_at'] ? substr($row['printed_at'], 0, 16) : '—';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($order_date_bs) ?></td>
                            <td><?= htmlspecialchars($printed_at_bs) ?></td>
                            <td><?= htmlspecialchars($table_display) ?></td>
                            <td class="items-list text-start"><?= htmlspecialchars($items_list) ?></td>
                            <td><strong><?= htmlspecialchars($row['bill_number'] ?? '—') ?></strong></td>
                            <td><strong><?= number_format($row['net_total'], 2) ?></strong></td>
                            <td><?= ucfirst(htmlspecialchars($row['payment_method'] ?? '—')) ?></td>
                            <td>
                                <a href="bill.php?order_id=<?= $row['id'] ?>" target="_blank" class="btn btn-success btn-sm no-print">View Bill</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-muted">No paid orders found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include('../Common/footer.php'); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>