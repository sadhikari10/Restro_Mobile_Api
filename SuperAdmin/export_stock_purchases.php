<?php
// export_stock_purchases.php
session_start();
require '../vendor/autoload.php';
require '../Common/connection.php';
require '../Common/nepali_date.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

// Security check
if (empty($_SESSION['logged_in']) || empty($_SESSION['current_restaurant_id'])) {
    die("Access denied. Please log in.");
}

$restaurant_id   = (int)$_SESSION['current_restaurant_id'];
$restaurant_name = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $_SESSION['current_restaurant_name'] ?? 'Restaurant');
$restaurant_name = $restaurant_name ?: 'Restaurant';

// Use session filters or default to last 1 month
$today_ad         = date('Y-m-d');
$today_bs         = ad_to_bs($today_ad);
$one_month_ago_ad = date('Y-m-d', strtotime('-1 month'));
$one_month_ago_bs = ad_to_bs($one_month_ago_ad);

$start_date = $_SESSION['ps_start'] ?? $one_month_ago_bs;
$end_date   = $_SESSION['ps_end']   ?? $today_bs;

// Build query safely
$sql = "SELECT p.*, u.username AS entered_by
        FROM purchase p
        LEFT JOIN users u ON p.added_by = u.id
        WHERE p.restaurant_id = ?";

$params = [$restaurant_id];
$types  = "i";

if ($start_date && $end_date) {
    $sql .= " AND p.transaction_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

$sql .= " ORDER BY p.transaction_date DESC, p.id DESC";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("MySQL Prepare Error: " . $conn->error);
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$purchases = [];
$grand_net_total = 0;

while ($row = $result->fetch_assoc()) {
    $items = json_decode($row['items_json'] ?? '[]', true) ?? [];
    $row['items'] = $items;
    $grand_net_total += $row['net_total'];
    $purchases[] = $row;
}
$stmt->close();

// Generate filename
$clean_start = str_replace('-', '', $start_date);
$clean_end   = str_replace('-', '', $end_date);
$filename    = "Stock_Purchases_{$restaurant_name}_{$clean_start}_to_{$clean_end}.xlsx";

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Title
$sheet->setCellValue('A1', 'STOCK PURCHASE REGISTER');
$sheet->mergeCells('A1:N1');
$sheet->getStyle('A1')->getFont()->setSize(18)->setBold(true);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Restaurant & Period
$sheet->setCellValue('A2', "Restaurant: {$restaurant_name}");
$sheet->mergeCells('A2:G2');
$sheet->getStyle('A2')->getFont()->setBold(true);

$sheet->setCellValue('H2', "Period: {$start_date} to {$end_date} (BS)");
$sheet->mergeCells('H2:N2');
$sheet->getStyle('H2')->getFont()->setBold(true);
$sheet->getStyle('H2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// Export info
$sheet->setCellValue('A3', 'Exported on: ' . nepali_date_time());
$sheet->mergeCells('A3:G3');

// Headers
$headers = [
    'S.N.', 'Bill No', 'Bill Date (BS)', 'Fiscal Year', 'Company', 'VAT No', 'Address',
    'Item Name', 'Qty', 'Unit', 'Rate (Rs)', 'Amount (Rs)', 'VAT %', 'VAT Amount (Rs)', 'Entered By'
];
$sheet->fromArray($headers, null, 'A6');
$sheet->getStyle('A6:O6')->getFont()->setBold(true);
$sheet->getStyle('A6:O6')->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('1a73e8');
$sheet->getStyle('A6:O6')->getFont()->getColor()->setARGB('FFFFFFFF');

// Data rows
$row = 7;
$sn  = 1;

foreach ($purchases as $p) {
    $items = $p['items'];
    $first_item = true;
    $subtotal = 0;

    if (empty($items)) {
        $sheet->setCellValue("A$row", $sn++);
        $sheet->setCellValue("B$row", $p['bill_no'] ?? '');
        $sheet->setCellValue("C$row", $p['transaction_date']);
        $sheet->setCellValue("D$row", $p['fiscal_year']);
        $sheet->setCellValue("E$row", $p['company_name']);
        $sheet->setCellValue("F$row", $p['vat_no'] ?? '');
        $sheet->setCellValue("G$row", $p['address'] ?? '');
        $sheet->setCellValue("H$row", '(No items)');
        $sheet->setCellValue("O$row", $p['entered_by'] ?? 'Unknown');
        $row += 3;
        continue;
    }

    foreach ($items as $it) {
        $qty  = (float)($it['quantity'] ?? 0);
        $rate = (float)($it['rate'] ?? 0);
        $amt  = $qty * $rate;
        $subtotal += $amt;

        $sheet->setCellValue("A$row", $first_item ? $sn++ : '');
        $sheet->setCellValue("B$row", $first_item ? ($p['bill_no'] ?? '') : '');
        $sheet->setCellValue("C$row", $first_item ? $p['transaction_date'] : '');
        $sheet->setCellValue("D$row", $first_item ? $p['fiscal_year'] : '');
        $sheet->setCellValue("E$row", $first_item ? $p['company_name'] : '');
        $sheet->setCellValue("F$row", $first_item ? ($p['vat_no'] ?? '') : '');
        $sheet->setCellValue("G$row", $first_item ? ($p['address'] ?? '') : '');
        $sheet->setCellValue("H$row", $it['name'] ?? 'Unknown');
        $sheet->setCellValue("I$row", number_format($qty, 3));
        $sheet->setCellValue("J$row", $it['unit'] ?? '');
        $sheet->setCellValue("K$row", number_format($rate, 2));
        $sheet->setCellValue("L$row", number_format($amt, 2));
        $sheet->setCellValue("M$row", $first_item ? $p['vat_percent'] : '');
        $sheet->setCellValue("N$row", ''); // VAT Amount filled later
        $sheet->setCellValue("O$row", $first_item ? ($p['entered_by'] ?? 'Unknown') : '');

        $sheet->getStyle("I$row:N$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $first_item = false;
        $row++;
    }

    // Subtotal
    $sheet->setCellValue("K$row", 'Subtotal:');
    $sheet->setCellValue("L$row", number_format($subtotal, 2));
    $sheet->getStyle("K$row:L$row")->getFont()->setBold(true);

    $row++;

    // Discount (always shown, even if 0)
    $discount = $p['discount'] ?? 0;
    $sheet->setCellValue("K$row", 'Discount:');
    $sheet->setCellValue("L$row", number_format($discount, 2));
    $sheet->getStyle("K$row:L$row")->getFont()->setBold(true);
    $sheet->getStyle("L$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFCCBC');

    $row++;

    // VAT % and VAT Amount
    $vat_percent = $p['vat_percent'] ?? 0;
    $taxable = $subtotal - $discount;
    $vat_amount = $taxable * ($vat_percent / 100);

    $sheet->setCellValue("K$row", 'VAT:');
    $sheet->setCellValue("M$row", $vat_percent . '%');
    $sheet->setCellValue("N$row", number_format($vat_amount, 2));
    $sheet->getStyle("K$row:N$row")->getFont()->setBold(true);
    $sheet->getStyle("N$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $row++;

    // Net Total
    $sheet->setCellValue("K$row", 'Net Total:');
    $sheet->setCellValue("L$row", number_format($p['net_total'], 2));
    $sheet->getStyle("K$row:L$row")->getFont()->setBold(true)->getColor()->setARGB('FF006400');
    $sheet->getStyle("K$row:L$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF90EE90');

    $row += 2; // separator
}

// Final Grand Total
$final_row = $row;
$sheet->setCellValue("K$final_row", 'GRAND TOTAL:');
$sheet->setCellValue("L$final_row", number_format($grand_net_total, 2));
$sheet->getStyle("K$final_row:L$final_row")
    ->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle("K$final_row:L$final_row")->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF228B22');
$sheet->getStyle("L$final_row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// Auto-size columns
foreach (range('A', 'O') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Borders
$sheet->getStyle("A6:O$final_row")->getBorders()->getAllBorders()
    ->setBorderStyle(Border::BORDER_THIN);

// Output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');
header('Expires: 0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>