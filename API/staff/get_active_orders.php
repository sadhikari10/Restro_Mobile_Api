<?php
// api/staff/get_active_orders.php
// Protected GET endpoint: Returns paginated active orders for the staff's restaurant
// Requires valid JWT (staff role)
// Supports: ?limit=20&offset=0&status=pending&from_date=YYYY-MM-DD&to_date=YYYY-MM-DD

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');  // Tighten in production
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Dependencies
require_once '../../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once '../../Common/connection.php';  // Your DB connection

// JWT Secret
$JWT_SECRET = $_ENV['JWT_SECRET'] ?? null;
if (empty($JWT_SECRET)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server configuration error']);
    exit;
}

// Only GET allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only GET method is allowed']);
    exit;
}

try {
    // Extract & validate JWT
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication token required']);
        exit;
    }

    $token = $matches[1];
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $userData = $decoded->data ?? null;

    if (!$userData || !isset($userData->role) || $userData->role !== 'staff') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied - staff role required']);
        exit;
    }

    $restaurant_id = (int)($userData->restaurant_id ?? 0);
    if ($restaurant_id <= 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid restaurant context']);
        exit;
    }

    // Parse query params with defaults & validation
    $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;  // Max 100 to prevent abuse
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $status = trim($_GET['status'] ?? '');  // e.g., 'pending' or 'preparing'
    $from_date = trim($_GET['from_date'] ?? '');  // YYYY-MM-DD
    $to_date = trim($_GET['to_date'] ?? '');

    // Build dynamic WHERE clause
    $where = "WHERE restaurant_id = ? AND status IN ('pending', 'preparing')";
    $params = [$restaurant_id];
    $types = 'i';

    if ($status !== '') {
        $where .= " AND status = ?";
        $params[] = $status;
        $types .= 's';
    }

    if ($from_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
        $where .= " AND created_at >= ?";
        $params[] = $from_date . ' 00:00:00';
        $types .= 's';
    }

    if ($to_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
        $where .= " AND created_at <= ?";
        $params[] = $to_date . ' 23:59:59';
        $types .= 's';
    }

    // Count total for pagination
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM orders $where");
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total_result = $count_stmt->get_result();
    $total = $total_result->fetch_assoc()['total'] ?? 0;
    $count_stmt->close();

    // Fetch paginated orders
    $stmt = $conn->prepare("
        SELECT id, table_number, items, total_amount, created_at, status
        FROM orders
        $where
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();

    // Success response with metadata
    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ], JSON_NUMERIC_CHECK);

} catch (Firebase\JWT\ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token has expired']);
} catch (Firebase\JWT\SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token signature']);
} catch (Exception $e) {
    error_log('get_active_orders error: ' . $e->getMessage());  // Log to server logs
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error - please try again later']);
}

exit;