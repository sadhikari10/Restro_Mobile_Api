<?php
// api/common/check_expiry.php
// Lightweight endpoint to check if a restaurant is expired (used by mobile)

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
require_once '../../Common/nepali_date.php';

$JWT_SECRET = $_ENV['JWT_SECRET'] ?? null;
if (empty($JWT_SECRET)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server configuration error']);
    exit;
}

// Only GET allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only GET allowed']);
    exit;
}

// Get restaurant_id from query
$restaurant_id = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
if ($restaurant_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'restaurant_id required']);
    exit;
}

// Optional: verify token (recommended for security)
$token = null;
$headers = getallheaders();
if (isset($headers['Authorization'])) {
    $token = $headers['Authorization'];
} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $token = $_SERVER['HTTP_AUTHORIZATION'];
}

if ($token && preg_match('/Bearer\s+(.+)/i', $token, $matches)) {
    $token = $matches[1];
}

if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    // You can add role check if needed: if ($decoded->data->role !== 'superadmin') { ... }

    // Get current BS date
    $current_bs_full = nepali_date_time();
    $today_bs = substr($current_bs_full, 0, 10); // YYYY-MM-DD

    $stmt = $conn->prepare("
        SELECT 
            expiry_date,
            is_trial,
            ? > expiry_date AS is_expired
        FROM restaurants 
        WHERE id = ?
    ");
    $stmt->bind_param("si", $today_bs, $restaurant_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $expired = (bool)$row['is_expired'];
        $is_trial = (bool)$row['is_trial'];

        echo json_encode([
            'success' => true,
            'expired' => $expired,
            'expiry_date' => $row['expiry_date'],
            'is_trial' => $is_trial,
            'message' => $expired
                ? ($is_trial ? 'Trial period has expired' : 'Subscription has expired')
                : null
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Restaurant not found']);
    }
    $stmt->close();

} catch (\Firebase\JWT\ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token expired']);
} catch (\Firebase\JWT\SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}

exit;