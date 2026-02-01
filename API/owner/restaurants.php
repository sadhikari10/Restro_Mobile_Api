<?php
// api/owner/restaurants.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');           // tighten later
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
require_once '../../Common/nepali_date.php';   // Adjust path if needed

// ─── JWT Secret ────────────────────────────────────────
$JWT_SECRET = $_ENV['JWT_SECRET'] ?? null;
if (empty($JWT_SECRET)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server configuration error']);
    exit;
}
echo('hi');

// ─── Only GET allowed ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ─── Get Authorization header ──────────────────────────
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token required']);
    exit;
}

$jwt = $matches[1];

try {
    // Decode & validate JWT
    $decoded = JWT::decode($jwt, new Key($JWT_SECRET, 'HS256'));
    $user = (array) $decoded->data;

    if (!isset($user['role']) || $user['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    $user_id = (int) $user['user_id'];
    $chain_id = null;

    // Get chain_id for this superadmin
    $stmt = $conn->prepare("SELECT chain_id FROM users WHERE id = ? AND role = 'superadmin'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $chain_id = $row['chain_id'];
    }
    $stmt->close();

    if (!$chain_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No chain associated']);
        exit;
    }

    // Fetch all restaurants in this chain
    $stmt = $conn->prepare("
        SELECT 
            id, name, address, phone_number, 
            expiry_date, is_trial, created_at
        FROM restaurants 
        WHERE chain_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param("i", $chain_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $restaurants = [];
    while ($row = $result->fetch_assoc()) {
        // Optional: compute friendly status
        $today_bs = substr(nepali_date_time(), 0, 10); // need nepali_date.php
        $status = ($row['expiry_date'] < $today_bs)
            ? 'Expired'
            : ($row['is_trial'] ? 'Trial' : 'Active');

        $row['status'] = $status;
        $restaurants[] = $row;
    }
    $stmt->close();

    echo json_encode([
        'success'     => true,
        'restaurants' => $restaurants,
        'count'       => count($restaurants)
    ]);

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