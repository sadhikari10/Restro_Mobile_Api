<?php
// api/owner/get_active_orders.php
// GET - Returns active orders for a specific restaurant
// Protected by JWT - superadmin only

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

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
    echo json_encode(['success' => false, 'error' => 'Server configuration error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only GET allowed']);
    exit;
}

// ─── JWT validation ───────────────────────────────────────────────
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token required']);
    exit;
}
$token = $matches[1];

try {
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $user = (array)$decoded->data;

    if (!isset($user['role']) || $user['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Superadmin only']);
        exit;
    }

    $user_id = (int)$user['user_id'];

    // ─── restaurant_id from query ──────────────────────────────────
    $restaurant_id = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
    if ($restaurant_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'restaurant_id required']);
        exit;
    }

    // ─── Verify ownership ──────────────────────────────────────────
    $check = $conn->prepare("
        SELECT chain_id 
        FROM restaurants 
        WHERE id = ?
    ");
    $check->bind_param("i", $restaurant_id);
    $check->execute();
    $res = $check->get_result();
    if ($res->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid restaurant']);
        exit;
    }
    $chain_id = (int)$res->fetch_assoc()['chain_id'];
    $check->close();

    $owner_check = $conn->prepare("
        SELECT 1 FROM users 
        WHERE id = ? AND chain_id = ? AND role = 'superadmin'
        LIMIT 1
    ");
    $owner_check->bind_param("ii", $user_id, $chain_id);
    $owner_check->execute();
    if ($owner_check->get_result()->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized restaurant']);
        exit;
    }
    $owner_check->close();

    // ─── Query params ──────────────────────────────────────────────
    $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $status = trim($_GET['status'] ?? 'preparing');  // Default to preparing
    $from_date = trim($_GET['from_date'] ?? '');
    $to_date = trim($_GET['to_date'] ?? '');

    // ─── WHERE clause ──────────────────────────────────────────────
    $where = "WHERE restaurant_id = ? AND status = ?";
    $params = [$restaurant_id, $status];
    $types = 'is';

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

    // ─── Count total ───────────────────────────────────────────────
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM orders $where");
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $count_stmt->close();

    // ─── Fetch orders ──────────────────────────────────────────────
    $stmt = $conn->prepare("
        SELECT id, table_number, items, total_amount, created_at, status
        FROM orders $where
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

    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ], JSON_NUMERIC_CHECK);

} catch (\Firebase\JWT\ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token expired']);
} catch (\Firebase\JWT\SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

exit;