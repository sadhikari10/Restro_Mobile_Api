<?php
// api/owner/restaurants.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only GET allowed']);
    exit;
}

// ────────────────────────────────────────────────
// Improved token extraction (handles common header issues)
// ────────────────────────────────────────────────
$token = null;

// Method 1: Standard getallheaders() (Apache)
$headers = getallheaders();
if (isset($headers['Authorization'])) {
    $token = $headers['Authorization'];
} elseif (isset($headers['authorization'])) {
    $token = $headers['authorization'];
}

// Method 2: Fallback to $_SERVER (sometimes more reliable)
if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $token = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (!$token && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $token = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

// Method 3: Parse from raw header if still missing
if (!$token) {
    $authHeader = '';
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'authorization') {
            $authHeader = $v;
            break;
        }
    }
    if ($authHeader) $token = $authHeader;
}

// Clean up to get just the token part
if ($token && preg_match('/Bearer\s+(.+)/i', $token, $matches)) {
    $token = $matches[1];
} else {
    $token = null;
}

// ────────────────────────────────────────────────
if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication token required']);
    exit;
}

// Now proceed with JWT validation
try {
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $user = (array) $decoded->data;

    if (!isset($user['role']) || $user['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    $user_id = (int)$user['user_id'];

    // Get chain_id
    $stmt = $conn->prepare("SELECT chain_id FROM users WHERE id = ? AND role = 'superadmin'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No chain associated']);
        exit;
    }
    $row = $result->fetch_assoc();
    $chain_id = (int)$row['chain_id'];
    $stmt->close();

    // Fetch restaurants
    $stmt = $conn->prepare("
        SELECT id, name, address, phone_number, expiry_date, is_trial, created_at 
        FROM restaurants 
        WHERE chain_id = ? 
        ORDER BY name ASC
    ");
    $stmt->bind_param("i", $chain_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $restaurants = [];
    $today_bs = substr(nepali_date_time(), 0, 10);

    while ($row = $result->fetch_assoc()) {
        $status = ($row['expiry_date'] < $today_bs) ? 'Expired' : 
                  ($row['is_trial'] == 1 ? 'Trial' : 'Active');
        $row['status'] = $status;
        $restaurants[] = $row;
    }
    $stmt->close();

    echo json_encode([
        'success'     => true,
        'count'       => count($restaurants),
        'restaurants' => $restaurants
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