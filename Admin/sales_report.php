<?php
session_start();
require '../Common/connection.php';
require '../Common/nepali_date.php';
require '../vendor/autoload.php';


    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    use PhpOffice\PhpSpreadsheet\Style\Alignment;
    use PhpOffice\PhpSpreadsheet\Style\Fill;
    use PhpOffice\PhpSpreadsheet\Style\Font;
    use PhpOffice\PhpSpreadsheet\Style\Border;

if (empty($_SESSION['logged_in']) || empty($_SESSION['restaurant_id'])) {
    header('Location: ../Common/login.php');
    exit;
}

// ONLY CHANGE: Use admin session variables instead of superadmin ones
$restaurant_id = (int)$_SESSION['restaurant_id'];
$restaurant_name = $_SESSION['restaurant_name'] ?? 'Restaurant';

// === Get fiscal years ===
$min_date_query = "SELECT MIN(created_at) as min_date FROM orders WHERE restaurant_id = ?";
$min_date_stmt = $conn->prepare($min_date_query);
$min_bs_date = '2082-01-01';
if ($min_date_stmt) {
    $min_date_stmt->bind_param("i", $restaurant_id);
    $min_date_stmt->execute();
    $min_date_result = $min_date_stmt->get_result();
    $min_date_row = $min_date_result->fetch_assoc();
    $min_bs_date = $min_date_row['min_date'] ?? '2082-01-01';
    $min_date_stmt->close();
}

$min_bs_parts = explode('-', $min_bs_date);
$min_bs_year = isset($min_bs_parts[0]) ? (int)$min_bs_parts[0] : 2082;

$current_bs_date = ad_to_bs(date('Y-m-d'));
$current_fy = get_fiscal_year($current_bs_date);
$current_fy_parts = explode('/', $current_fy);
$current_start = (int)$current_fy_parts[0];

$fiscal_years = [];
for ($i = $current_start; $i >= $min_bs_year; $i--) {
    $fy_start = $i;
    $fy_end = $i + 1;
    $fiscal_years[] = $fy_start . '/' . substr($fy_end, -2);
}

// === HELPER FUNCTIONS ===
function get_fiscal_dates(string $fiscal_year): array {
    [$start_year, $end_year_2digit] = explode('/', $fiscal_year);
    $end_year = (int)$end_year_2digit + 2000;
    return [
        'bs_start' => "{$start_year}-04-01", 
        'bs_end' => "{$end_year}-03-31"
    ];
}

function get_month_dates(string $month, string $fiscal_year): array {
    [$fy_start] = explode('/', $fiscal_year);
    $days_in_month = [31, 32, 31, 32, 31, 31, 30, 30, 30, 29, 30, 29];
    $month_index = (int)$month - 1;
    $days = $days_in_month[$month_index] ?? 30;
    return [
        'bs_start' => "{$fy_start}-{$month}-01", 
        'bs_end' => "{$fy_start}-{$month}-{$days}"
    ];
}

function get_week_dates(string $month, string $fiscal_year, int $week_num = 1): array {
    [$fy_start] = explode('/', $fiscal_year);
    $days_in_month = [31, 32, 31, 32, 31, 31, 30, 30, 30, 29, 30, 29];
    $days = $days_in_month[(int)$month - 1] ?? 30;
    
    $week_start_day = ($week_num - 1) * 7 + 1;
    $week_end_day = min($week_start_day + 6, $days);
    
    return [
        'bs_start' => "{$fy_start}-{$month}-" . sprintf('%02d', $week_start_day),
        'bs_end' => "{$fy_start}-{$month}-" . sprintf('%02d', $week_end_day)
    ];
}

function get_date_range(string $period, string $fiscal_year, string $nepali_month = '', string $specific_date = '', string $week_num = ''): array {
    $fiscal_dates = get_fiscal_dates($fiscal_year);
    
    switch ($period) {
        case 'year':
            return $fiscal_dates;
        case 'month':
            return $nepali_month ? get_month_dates($nepali_month, $fiscal_year) : $fiscal_dates;
        case 'week':
            return $nepali_month && $week_num ? get_week_dates($nepali_month, $fiscal_year, (int)$week_num) : $fiscal_dates;
        case 'day':
            return ['bs_start' => $specific_date, 'bs_end' => $specific_date];
        default:
            return $fiscal_dates;
    }
}

// === FILTER HANDLING ===
$period = $_POST['period'] ?? 'year';
$fiscal_year = $_POST['fiscal_year'] ?? $fiscal_years[0];
$nepali_month = $_POST['nepali_month'] ?? '';
$specific_date = $_POST['specific_date'] ?? '';
$week_num = $_POST['week_num'] ?? '1';

$date_range = get_date_range($period, $fiscal_year, $nepali_month, $specific_date, $week_num);
$period_start = $date_range['bs_start'];
$period_end = $date_range['bs_end'];

$nepali_months = [
    '01' => 'बैशाख', '02' => 'जेठ', '03' => 'असार',
    '04' => 'साउन', '05' => 'भदौ', '06' => 'असोज',
    '07' => 'कात्तिक', '08' => 'मंसिर', '09' => 'पुष',
    '10' => 'माघ', '11' => 'फागुन', '12' => 'चैत'
];

// === LOAD MENU ITEMS WITH PRICES ===
$menu_items = [];
$menu_query = "SELECT id, item_name, price FROM menu_items WHERE restaurant_id = ? AND status = 'available'";
$menu_stmt = $conn->prepare($menu_query);
$menu_count = 0;
if ($menu_stmt) {
    $menu_stmt->bind_param("i", $restaurant_id);
    $menu_stmt->execute();
    $menu_result = $menu_stmt->get_result();
    while ($row = $menu_result->fetch_assoc()) {
        $menu_items[$row['id']] = [
            'name' => $row['item_name'],
            'price' => (float)$row['price']
        ];
        $menu_count++;
    }
    $menu_stmt->close();
}

// === SALES DATA ===
$sales_summary = ['daily' => [], 'items' => [], 'totals' => ['sales' => 0, 'items_sold' => 0]];
$item_totals = [];

// 1. DAILY SALES
$daily_query = "SELECT DATE(created_at) as sale_date, SUM(total_amount) as daily_total 
                FROM orders WHERE restaurant_id = ? AND status = 'paid' 
                AND created_at BETWEEN ? AND ?
                GROUP BY DATE(created_at) ORDER BY sale_date";
$daily_stmt = $conn->prepare($daily_query);
if ($daily_stmt) {
    $daily_stmt->bind_param("iss", $restaurant_id, $period_start, $period_end);
    $daily_stmt->execute();
    $daily_result = $daily_stmt->get_result();
    while ($row = $daily_result->fetch_assoc()) {
        $sales_summary['daily'][$row['sale_date']] = (float)$row['daily_total'];
        $sales_summary['totals']['sales'] += (float)$row['daily_total'];
    }
    $daily_stmt->close();
}

// 2. ITEM-WISE SALES WITH PROPER PRICING
$order_query = "SELECT id, items, total_amount, created_at FROM orders 
                WHERE restaurant_id = ? AND status = 'paid' 
                AND created_at BETWEEN ? AND ? ORDER BY created_at";
$order_stmt = $conn->prepare($order_query);
if ($order_stmt) {
    $order_stmt->bind_param("iss", $restaurant_id, $period_start, $period_end);
    $order_stmt->execute();
    $order_result = $order_stmt->get_result();
    
    while ($order_row = $order_result->fetch_assoc()) {
        $items_json = json_decode($order_row['items'], true);
        
        if ($items_json && is_array($items_json)) {
            foreach ($items_json as $item) {
                $item_id = (int)($item['item_id'] ?? 0);
                $quantity = (float)($item['quantity'] ?? 0);
                
                if ($item_id > 0 && $quantity > 0 && isset($menu_items[$item_id])) {
                    $item_name = $menu_items[$item_id]['name'];
                    $item_price = $menu_items[$item_id]['price'];
                    $item_amount = $quantity * $item_price;
                    
                    if (!isset($item_totals[$item_name])) {
                        $item_totals[$item_name] = ['quantity' => 0, 'amount' => 0, 'price' => $item_price];
                    }
                    $item_totals[$item_name]['quantity'] += $quantity;
                    $item_totals[$item_name]['amount'] += $item_amount;
                }
            }
        }
    }
    $order_stmt->close();
}

$sales_summary['items'] = $item_totals;
foreach ($item_totals as $data) {
    $sales_summary['totals']['items_sold'] += $data['quantity'];
}

// === PURCHASE DATA (including general bills) ===
$purchases = ['total' => 0.00];

// 1. Regular purchases from 'purchase' table
$purchase_query = "SELECT SUM(net_total) as purchase_total 
                   FROM purchase 
                   WHERE restaurant_id = ? 
                     AND DATE(transaction_date) BETWEEN ? AND ?";
$purchase_stmt = $conn->prepare($purchase_query);
$regular_total = 0.00;
if ($purchase_stmt) {
    $purchase_stmt->bind_param("iss", $restaurant_id, $period_start, $period_end);
    $purchase_stmt->execute();
    $purchase_result = $purchase_stmt->get_result();
    $row = $purchase_result->fetch_assoc();
    $regular_total = (float)($row['purchase_total'] ?? 0);
    $purchase_stmt->close();
}

// 2. General bills from 'general_bill' table
$general_total = 0.00;
$general_query = "SELECT items_json 
                  FROM general_bill 
                  WHERE restaurant_id = ? 
                    AND purchase_date BETWEEN ? AND ?";
$general_stmt = $conn->prepare($general_query);
if ($general_stmt) {
    $general_stmt->bind_param("iss", $restaurant_id, $period_start, $period_end);
    $general_stmt->execute();
    $general_result = $general_stmt->get_result();
    
    while ($row = $general_result->fetch_assoc()) {
        $items_json = $row['items_json'];
        $items = json_decode($items_json, true);
        
        if (is_array($items)) {
            foreach ($items as $item) {
                $quantity = (float)($item['quantity'] ?? 0);
                $rate     = (float)($item['rate'] ?? 0);
                $general_total += $quantity * $rate;
            }
        }
    }
    $general_stmt->close();
}

// Total purchases (regular + general)
$purchases['total'] = $regular_total + $general_total;

// === NET PROFIT (now: Sales - Total Purchases) ===
$net_profit = $sales_summary['totals']['sales'] - $purchases['total'];

// === CREDIT CUSTOMERS DATA (for the selected period) ===
$credit_summary = [
    'total_consumed' => 0.00,
    'total_paid'     => 0.00,
    'remaining_due'  => 0.00
];

// We need to sum credit-related amounts from orders where payment was not full (i.e., credit orders)
// Assuming your orders table has a column like `payment_status` or you track credit via customer_id ≠ NULL
// Most common pattern: orders have `customer_id` > 0 when sold on credit

$credit_query = "
    SELECT 
        COALESCE(SUM(total_amount), 0) AS consumed,
        COALESCE(SUM(paid_amount), 0) AS paid
    FROM orders 
    WHERE restaurant_id = ? 
      AND status = 'paid'  -- or whatever status means completed
      AND customer_id IS NOT NULL AND customer_id > 0
      AND created_at BETWEEN ? AND ?
";

$credit_stmt = $conn->prepare($credit_query);
if ($credit_stmt) {
    $credit_stmt->bind_param("iss", $restaurant_id, $period_start, $period_end);
    $credit_stmt->execute();
    $credit_result = $credit_stmt->get_result();
    $credit_row = $credit_result->fetch_assoc();
    
    $credit_summary['total_consumed'] = (float)$credit_row['consumed'];
    $credit_summary['total_paid']     = (float)$credit_row['paid'];
    $credit_summary['remaining_due']  = $credit_summary['total_consumed'] - $credit_summary['total_paid'];
    
    $credit_stmt->close();
} else {
    // Fallback: if orders table doesn't have paid_amount, just show remaining from customers table
    // (less accurate, includes old dues)
    $fallback_query = "
        SELECT 
            SUM(total_consumed) AS consumed,
            SUM(total_paid) AS paid,
            SUM(remaining_due) AS due
        FROM customers 
        WHERE restaurant_id = ?
    ";
    $fb_stmt = $conn->prepare($fallback_query);
    if ($fb_stmt) {
        $fb_stmt->bind_param("i", $restaurant_id);
        $fb_stmt->execute();
        $fb_res = $fb_stmt->get_result();
        $fb_row = $fb_res->fetch_assoc();
        $credit_summary['total_consumed'] = (float)($fb_row['consumed'] ?? 0);
        $credit_summary['total_paid']     = (float)($fb_row['paid'] ?? 0);
        $credit_summary['remaining_due']  = (float)($fb_row['due'] ?? 0);
        $fb_stmt->close();
    }
}
// === PROFIT ===
$net_profit = $sales_summary['totals']['sales'] - $purchases['total'];

// === TOP ITEMS ===
$top_items = [];
foreach ($sales_summary['items'] as $name => $data) {
    if ($data['quantity'] > 0) {
        $top_items[] = [
            'name' => $name, 
            'qty' => $data['quantity'], 
            'amount' => $data['amount'],
            'price' => $data['price']
        ];
    }
}
usort($top_items, fn($a, $b) => $b['qty'] <=> $a['qty']);
$top_5 = array_slice($top_items, 0, 5);
// === EXCEL EXPORT ===
if (isset($_POST['export_excel'])) {
    ob_end_clean(); // Clear any output before sending file
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Title
    $sheet->setCellValue('A1', 'SALES & PROFIT REPORT');
    $sheet->mergeCells('A1:F1');
    $sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Restaurant & Period
    $sheet->setCellValue('A2', "Restaurant: {$restaurant_name}");
    $sheet->mergeCells('A2:C2');
    $sheet->getStyle('A2')->getFont()->setBold(true);

    $sheet->setCellValue('D2', "Period: {$period_start} to {$period_end}");
    $sheet->mergeCells('D2:F2');
    $sheet->getStyle('D2')->getFont()->setBold(true);
    $sheet->getStyle('D2:F2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // === Key Metrics (Clean & Colorless) ===
    $sheet->setCellValue('A4', 'Total Sales');
    $sheet->setCellValue('B4', 'Rs. ' . number_format($sales_summary['totals']['sales'], 2));
    $sheet->getStyle('A4')->getFont()->setBold(true);
    $sheet->getStyle('B4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet->setCellValue('C4', 'Items Sold');
    $sheet->setCellValue('D4', number_format($sales_summary['totals']['items_sold'], 0));
    $sheet->getStyle('C4')->getFont()->setBold(true);
    $sheet->getStyle('D4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet->setCellValue('E4', 'Total Purchases');
    $sheet->setCellValue('F4', 'Rs. ' . number_format($purchases['total'], 2));
    $sheet->getStyle('E4')->getFont()->setBold(true);
    $sheet->getStyle('F4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet->setCellValue('A5', 'Net Profit');
    $sheet->setCellValue('B5', 'Rs. ' . number_format($net_profit, 2));
    $sheet->getStyle('A5')->getFont()->setBold(true);
    $sheet->getStyle('B5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // === Credit Summary ===
    $sheet->setCellValue('C5', 'Total Credit Sales');
    $sheet->setCellValue('D5', 'Rs. ' . number_format($credit_summary['total_consumed'], 2));
    $sheet->getStyle('C5')->getFont()->setBold(true);
    $sheet->getStyle('D5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet->setCellValue('E5', 'Credit Paid');
    $sheet->setCellValue('F5', 'Rs. ' . number_format($credit_summary['total_paid'], 2));
    $sheet->getStyle('E5')->getFont()->setBold(true);
    $sheet->getStyle('F5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet->setCellValue('A6', 'Remaining Due');
    $sheet->setCellValue('B6', 'Rs. ' . number_format($credit_summary['remaining_due'], 2));
    $sheet->getStyle('A6')->getFont()->setBold(true);
    $sheet->getStyle('B6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // === Top 5 Best Sellers Section ===
    $row = 8; // Start after key metrics
    $sheet->setCellValue('A' . $row, 'TOP 5 BEST SELLERS');
    $sheet->mergeCells('A' . $row . ':F' . $row);
    $sheet->getStyle('A' . $row)->getFont()->setSize(14)->setBold(true);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row++;

    // Headers
    $headers = ['Rank', 'Item Name', 'Qty Sold', 'Price (Rs)', 'Total Sales (Rs)', 'Percentage'];
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . $row, $h);
        $sheet->getStyle($col . $row)->getFont()->setBold(true);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }
    $row++;

    // Top 5 Data
    $total_qty = $sales_summary['totals']['items_sold'] ?: 1;
    foreach ($top_5 as $i => $item) {
        $percentage = round(($item['qty'] / $total_qty) * 100, 1);

        $sheet->setCellValue('A' . $row, $i + 1);
        $sheet->setCellValue('B' . $row, $item['name']);
        $sheet->setCellValue('C' . $row, $item['qty']);
        $sheet->setCellValue('D' . $row, number_format($item['price'], 2));
        $sheet->setCellValue('E' . $row, number_format($item['amount'], 2));
        $sheet->setCellValue('F' . $row, $percentage . '%');

        $sheet->getStyle('C' . $row . ':F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row++;
    }

    // === Complete Items Report ===
    $row += 1;
    $sheet->setCellValue('A' . $row, 'COMPLETE ITEMS REPORT (' . count($top_items) . ' Items)');
    $sheet->mergeCells('A' . $row . ':F' . $row);
    $sheet->getStyle('A' . $row)->getFont()->setSize(14)->setBold(true);
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row++;

    // Headers (same as top 5)
    $col = 'A';
    foreach ($headers as $index => $h) {
        if ($index === 4) $h = 'Total Sales (Rs)'; // Keep consistent
        $sheet->setCellValue($col . $row, $h);
        $sheet->getStyle($col . $row)->getFont()->setBold(true);
        $col++;
    }
    $row++;

    // Complete Data
    foreach ($top_items as $i => $item) {
        $percentage = round(($item['qty'] / $total_qty) * 100, 1);

        $sheet->setCellValue('A' . $row, $i + 1);
        $sheet->setCellValue('B' . $row, $item['name']);
        $sheet->setCellValue('C' . $row, $item['qty']);
        $sheet->setCellValue('D' . $row, number_format($item['price'], 2));
        $sheet->setCellValue('E' . $row, number_format($item['amount'], 2));
        $sheet->setCellValue('F' . $row, $percentage . '%');

        $sheet->getStyle('C' . $row . ':F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row++;
    }

    // Grand Total
    $sheet->setCellValue('B' . $row, 'GRAND TOTAL');
    $sheet->setCellValue('C' . $row, $sales_summary['totals']['items_sold']);
    $sheet->setCellValue('E' . $row, number_format($sales_summary['totals']['sales'], 2));
    $sheet->setCellValue('F' . $row, '100%');

    $sheet->getStyle('B' . $row . ':F' . $row)->getFont()->setBold(true);
    $sheet->getStyle('B' . $row . ':F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // Filename
    $clean_restaurant = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $restaurant_name);
    $filename = "Sales_Report_{$fiscal_year}_{$period}_{$clean_restaurant}_" . date('Y-m-d') . ".xlsx";

    // Send file
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    header('Expires: 0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sales & Profit Report - <?= htmlspecialchars($restaurant_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; min-height: 100vh; }
        .report-card { background: white; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); border: 1px solid #dee2e6; }
        .stat-card { transition: transform 0.2s; border: none; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15); }
        .stat-card:hover { transform: translateY(-2px); }
        .profit-positive { background: linear-gradient(135deg, #d4edda, #c3e6cb); color: #155724; }
        .profit-negative { background: linear-gradient(135deg, #f8d7da, #f5c6cb); color: #721c24; }
        .date-input { font-family: monospace; font-size: 14px; }
        .filter-group { transition: all 0.3s ease; }
    </style>
</head>
<body>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2 class="text-dark fw-bold mb-1">
                        <i class="bi bi-graph-up"></i> Sales & Profit Report
                    </h2>
                    <p class="text-muted mb-0">
                        <strong><?= htmlspecialchars($fiscal_year) ?></strong> 
                        | <?= htmlspecialchars($period_start) ?> → <?= htmlspecialchars($period_end) ?>
                    </p>
                </div>
                <div class="btn-group">
                    <form method="POST" style="display:inline;" target="_blank">
                        <input type="hidden" name="export_excel" value="1">
                        <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                        <input type="hidden" name="fiscal_year" value="<?= htmlspecialchars($fiscal_year) ?>">
                        <input type="hidden" name="nepali_month" value="<?= htmlspecialchars($nepali_month) ?>">
                        <input type="hidden" name="specific_date" value="<?= htmlspecialchars($specific_date) ?>">
                        <input type="hidden" name="week_num" value="<?= htmlspecialchars($week_num) ?>">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-file-earmark-excel"></i> Export Excel
                        </button>
                    </form>
                    <button>
                        <a href="dashboard.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                        </a>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- FILTERS -->
    <div class="card report-card mb-5">
        <div class="card-header bg-primary text-white">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Select Report Period</h6>
        </div>
        <div class="card-body">
            <form method="POST" id="reportFilter">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Fiscal Year</label>
                        <select name="fiscal_year" class="form-select">
                            <?php foreach (array_reverse($fiscal_years) as $fy): ?>
                                <option value="<?= htmlspecialchars($fy) ?>" <?= $fy === $fiscal_year ? 'selected' : '' ?>><?= $fy ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Period</label>
                        <select name="period" class="form-select" id="periodSelect" onchange="handlePeriodChange()">
                            <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>📅 Full Year</option>
                            <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>📆 By Month</option>
                            <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>📋 Weekly</option>
                            <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>🎯 Specific Date</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 filter-group" id="monthFilter" style="display: <?= ($period === 'month' || $period === 'week') ? 'block' : 'none' ?>;">
                        <label class="form-label fw-semibold">Month</label>
                        <select name="nepali_month" class="form-select" id="monthSelect" onchange="handleMonthChange()">
                            <option value="">Select Month</option>
                            <?php foreach ($nepali_months as $code => $name): ?>
                                <option value="<?= $code ?>" <?= $nepali_month === $code ? 'selected' : '' ?>><?= $name ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 filter-group" id="weekFilter" style="display: <?= ($period === 'week' && $nepali_month) ? 'block' : 'none' ?>;">
                        <label class="form-label fw-semibold">Week</label>
                        <select name="week_num" class="form-select">
                            <?php for ($w = 1; $w <= 5; $w++): ?>
                                <option value="<?= $w ?>" <?= $week_num == $w ? 'selected' : '' ?>>
                                    Week <?= $w ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2 filter-group" id="dateFilter" style="display: <?= $period === 'day' ? 'block' : 'none' ?>;">
                        <label class="form-label fw-semibold">Date (BS)</label>
                        <input type="text" name="specific_date" value="<?= htmlspecialchars($specific_date) ?>" 
                               class="form-control date-input" placeholder="2082-08-05" maxlength="10">
                        <div class="form-text">YYYY-MM-DD</div>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Generate Report
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- KEY METRICS -->
    <div class="row g-4 mb-5">
        <div class="col-xl-3 col-lg-6 col-md-6">
            <div class="card stat-card h-100 text-center bg-primary text-white">
                <div class="card-body py-4">
                    <i class="bi bi-currency-rupee fs-1"></i>
                    <h3 class="mt-2 fw-bold">Rs. <?= number_format($sales_summary['totals']['sales'], 2) ?></h3>
                    <p class="mb-2">Total Sales</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6">
            <div class="card stat-card h-100 text-center bg-warning text-dark">
                <div class="card-body py-4">
                    <i class="bi bi-box-seam fs-1"></i>
                    <h3 class="mt-2 fw-bold"><?= number_format($sales_summary['totals']['items_sold'], 0) ?></h3>
                    <p class="mb-2">Items Sold</p>
                </div>
            </div>
        </div>
                <!-- TOTAL PURCHASES (Regular + General Bills) -->
        <div class="col-xl-3 col-lg-6 col-md-6">
            <div class="card stat-card h-100 text-center bg-danger text-white">
                <div class="card-body py-4">
                    <i class="bi bi-cart-dash fs-1"></i>
                    <h3 class="mt-2 fw-bold">Rs. <?= number_format($purchases['total'], 2) ?></h3>
                    <p class="mb-2">Total Purchases</p>
                    <small>(Ingredients + General)</small>
                </div>
            </div>
        </div>

        <!-- NET PROFIT -->
        <div class="col-xl-3 col-lg-6 col-md-6">
            <div class="card stat-card h-100 text-center <?= $net_profit >= 0 ? 'bg-success text-white' : 'bg-danger text-white' ?>">
                <div class="card-body py-4">
                    <i class="bi bi-graph-up-arrow fs-1"></i>
                    <h3 class="mt-2 fw-bold">Rs. <?= number_format($net_profit, 2) ?></h3>
                    <p class="mb-2">Net Profit</p>
                    <small>(Sales - Total Purchases)</small>
                </div>
            </div>
        </div>
                <!-- CREDIT METRICS -->
        <div class="col-xl-3 col-lg-6 col-md-6">
            <div class="card stat-card h-100 text-center bg-danger text-white">
                <div class="card-body py-4">
                    <i class="bi bi-credit-card-2-back fs-1"></i>
                    <h3 class="mt-2 fw-bold">Rs. <?= number_format($credit_summary['total_consumed'], 2) ?></h3>
                    <p class="mb-2">Total Credit Sales</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6">
            <div class="card stat-card h-100 text-center bg-success text-white">
                <div class="card-body py-4">
                    <i class="bi bi-cash-coin fs-1"></i>
                    <h3 class="mt-2 fw-bold">Rs. <?= number_format($credit_summary['total_paid'], 2) ?></h3>
                    <p class="mb-2">Credit Paid</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6">
            <div class="card stat-card h-100 text-center <?= $credit_summary['remaining_due'] > 0 ? 'bg-warning text-dark' : 'bg-secondary text-white' ?>">
                <div class="card-body py-4">
                    <i class="bi bi-exclamation-triangle fs-1"></i>
                    <h3 class="mt-2 fw-bold">Rs. <?= number_format($credit_summary['remaining_due'], 2) ?></h3>
                    <p class="mb-2">Remaining Due</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6">
            <div class="card stat-card h-100 text-center bg-info text-white">
                <div class="card-body py-4">
                    <i class="bi bi-receipt fs-1"></i>
                    <h3 class="mt-2 fw-bold"><?= count($top_items) ?></h3>
                    <p class="mb-2">Unique Items</p>
                </div>
            </div>
        </div>
    </div>

    <!-- TOP 5 BEST SELLERS - ONLY QUANTITY -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card report-card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-trophy"></i> 🏆 Top 5 Best Sellers</h5>
                    <small class="text-light-50"><?= htmlspecialchars($period_start) ?> → <?= htmlspecialchars($period_end) ?></small>
                </div>
                <div class="card-body">
                    <?php if (empty($top_5)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-1 mb-3"></i>
                            <h5 class="mb-0">No sales data found</h5>
                        </div>
                    <?php else: ?>
                        <?php foreach ($top_5 as $i => $item): ?>
                            <div class="d-flex justify-content-between align-items-center py-3 border-bottom">
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-warning text-dark me-3 fs-4 fw-bold"><?= $i+1 ?></span>
                                    <div>
                                        <div class="fw-bold"><?= htmlspecialchars($item['name']) ?></div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="h4 fw-bold text-success mb-1"><?= number_format($item['qty']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- COMPLETE REPORT -->
    <div class="row g-4">
        <div class="col-12">
            <div class="card report-card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-list-ul"></i> 📋 Complete Sales Report
                        <span class="badge bg-light text-dark ms-2"><?= count($top_items) ?> Items</span>
                    </h5>
                    <small class="text-light-50"><?= htmlspecialchars($period_start) ?> → <?= htmlspecialchars($period_end) ?></small>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width: 50%">Item Name</th>
                                    <th class="text-end" style="width: 15%">Qty Sold</th>
                                    <th class="text-end" style="width: 15%">Price</th>
                                    <th class="text-end" style="width: 20%">Total Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($top_items)): ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">
                                        <i class="bi bi-graph-down fs-1 mb-3"></i>
                                        <h5 class="mb-3">No sales data available</h5>
                                    </td></tr>
                                <?php else: ?>
                                    <?php foreach ($top_items as $i => $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['name']) ?></td>
                                        <td class="text-end fw-bold text-primary"><?= number_format($item['qty'], 3) ?></td>
                                        <td class="text-end fw-monospace">Rs. <?= number_format($item['price'], 2) ?></td>
                                        <td class="text-end fw-bold text-success">Rs. <?= number_format($item['amount'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-success">
                                        <td class="fw-bold text-end" colspan="2"><h5>TOTAL</h5></td>
                                        <td></td>
                                        <td class="text-end fw-bold fs-5 text-success">
                                            <h5>Rs. <?= number_format($sales_summary['totals']['sales'], 2) ?></h5>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    handlePeriodChange();
});

function handlePeriodChange() {
    const period = document.getElementById('periodSelect').value;
    const monthFilter = document.getElementById('monthFilter');
    const weekFilter = document.getElementById('weekFilter');
    const dateFilter = document.getElementById('dateFilter');
    
    monthFilter.style.display = 'none';
    weekFilter.style.display = 'none';
    dateFilter.style.display = 'none';
    
    if (period === 'month' || period === 'week') {
        monthFilter.style.display = 'block';
        if (period === 'week' && document.getElementById('monthSelect').value) {
            weekFilter.style.display = 'block';
        }
    } else if (period === 'day') {
        dateFilter.style.display = 'block';
    }
}

function handleMonthChange() {
    const period = document.getElementById('periodSelect').value;
    const weekFilter = document.getElementById('weekFilter');
    if (period === 'week' && document.getElementById('monthSelect').value) {
        weekFilter.style.display = 'block';
    } else {
        weekFilter.style.display = 'none';
    }
}
</script>

<?php include '../Common/footer.php'; ?>
</body>
</html>