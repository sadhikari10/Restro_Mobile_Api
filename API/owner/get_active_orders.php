<?php
// api/owner/get_active_orders.php
// GET - Returns paginated active orders for a specific restaurant
// Protected by JWT - superadmin only
// Requires ?restaurant_id=... in query (superadmin has null in token)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once '../../Common/connection.php';

$JWT_SECRET = $_ENV['JWT_SECRET'] ?? null;
if (empty($JWT_SECRET)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server configuration error - JWT_SECRET missing']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only GET method allowed']);
    exit;
}

// ─── Robust token extraction ───────────────────────────────────────
$headers = getallheaders();
$token = null;

$possible_headers = ['X-Authorization', 'Authorization', 'x-authorization', 'authorization'];
foreach ($possible_headers as $h) {
    if (isset($headers[$h])) {
        $token = $headers[$h];
        break;
    }
}
if (!$token && isset($_SERVER['HTTP_X_AUTHORIZATION'])) $token = $_SERVER['HTTP_X_AUTHORIZATION'];
if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) $token = $_SERVER['HTTP_AUTHORIZATION'];

if ($token && preg_match('/Bearer\s+(.+)/i', $token, $matches)) {
    $token = trim($matches[1]);
} else {
    $token = null;
}

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication token required']);
    exit;
}

// ─── Decode JWT ────────────────────────────────────────────────────
try {
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $user = (array) $decoded->data;

    if (!isset($user['role']) || $user['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Superadmin access only']);
        exit;
    }

    $user_id = (int) $user['user_id'];

    // ─── restaurant_id MUST come from query string ─────────────────────
    $restaurant_id = isset($_GET['restaurant_id']) ? (int) $_GET['restaurant_id'] : 0;

    error_log("get_active_orders.php - Received restaurant_id from query: $restaurant_id");

    if ($restaurant_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'restaurant_id is required in query string (?restaurant_id=XX)'
        ]);
        exit;
    }

    // ─── Verify restaurant exists and belongs to this superadmin ───────
    $stmt = $conn->prepare("SELECT chain_id FROM restaurants WHERE id = ?");
    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid restaurant ID']);
        exit;
    }

    $chain_id = (int) $res->fetch_assoc()['chain_id'];
    $stmt->close();

    error_log("get_active_orders.php - Restaurant $restaurant_id belongs to chain_id $chain_id");

    $stmt = $conn->prepare("
        SELECT 1 FROM users 
        WHERE id = ? AND chain_id = ? AND role = 'superadmin'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $user_id, $chain_id);
    $stmt->execute();
    $check_res = $stmt->get_result();

    if ($check_res->num_rows === 0) {
        error_log("get_active_orders.php - Ownership check FAILED for user_id $user_id and chain_id $chain_id");
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have permission to access this restaurant']);
        exit;
    }

    error_log("get_active_orders.php - Ownership check PASSED for user_id $user_id and restaurant_id $restaurant_id");
    $stmt->close();

    // ─── Query parameters ──────────────────────────────────────────────
    $limit  = isset($_GET['limit'])  ? max(1, min(100, (int)$_GET['limit']))  : 20;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset'])          : 0;
    $status = trim($_GET['status'] ?? 'preparing');

    error_log("get_active_orders.php - Using status filter: '$status'");

    $from_date = trim($_GET['from_date'] ?? '');
    $to_date   = trim($_GET['to_date'] ?? '');

    // ─── Build WHERE clause ────────────────────────────────────────────
    $where  = "WHERE restaurant_id = ? AND status = ?";
    $params = [$restaurant_id, $status];
    $types  = 'is';

    if ($from_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
        $where .= " AND created_at >= ?";
        $params[] = $from_date . ' 00:00:00';
        $types .= 's';
    }

    if ($to_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
        $where .= " AND created_at <= ?";
        $params[] = $to_date . ' 23:59:59';
        $types .= 's';
    }

    // ─── Count total matching orders ───────────────────────────────────
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM orders $where");
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $count_stmt->close();

    error_log("get_active_orders.php - Total matching orders (before limit): $total");

    // ─── Fetch paginated orders ────────────────────────────────────────
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare("
        SELECT id, table_number, items, total_amount, created_at, status
        FROM orders $where
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();

    error_log("get_active_orders.php - Found " . count($orders) . " orders for restaurant_id $restaurant_id and status '$status'");

    // ─── Success response ──────────────────────────────────────────────
    echo json_encode([
        'success' => true,
        'orders'  => $orders,
        'total'   => $total,
        'limit'   => $limit,
        'offset'  => $offset
    ], JSON_NUMERIC_CHECK);

} catch (\Firebase\JWT\ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token has expired']);
} catch (\Firebase\JWT\SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token signature']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

exit;