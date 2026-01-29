<?php
// api/owner/my_restaurants.php
// Protected GET endpoint: Returns list of restaurants owned by the superadmin (via chain_id)
// Only superadmin role allowed

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');  // tighten in production
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once '../../Common/connection.php';

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
    // Extract Bearer Token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication token required']);
        exit;
    }

    $token = $matches[1];

    // Validate JWT
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $userData = $decoded->data ?? null;

    if (!$userData || !isset($userData->role)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid token data']);
        exit;
    }

    // Only superadmin (owner) allowed
    if ($userData->role !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied - superadmin role required']);
        exit;
    }

    $user_id = (int)($userData->user_id ?? 0);
    if ($user_id <= 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid user context']);
        exit;
    }

    // Get chain_id for this superadmin
    $chain_stmt = $conn->prepare("
        SELECT chain_id 
        FROM users 
        WHERE id = ? AND role = 'superadmin'
    ");
    $chain_stmt->bind_param("i", $user_id);
    $chain_stmt->execute();
    $chain_res = $chain_stmt->get_result();

    if ($chain_res->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No chain associated with this user']);
        exit;
    }

    $chain_row = $chain_res->fetch_assoc();
    $chain_id = (int)$chain_row['chain_id'];
    $chain_stmt->close();

    // Fetch all restaurants in this chain
    $stmt = $conn->prepare("
        SELECT 
            id, 
            name, 
            address, 
            phone_number, 
            expiry_date, 
            is_trial, 
            created_at 
        FROM restaurants 
        WHERE chain_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->bind_param("i", $chain_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $restaurants = [];
    while ($row = $result->fetch_assoc()) {
        $restaurants[] = $row;
    }
    $stmt->close();

    // Success response
    echo json_encode([
        'success'     => true,
        'chain_id'    => $chain_id,
        'restaurants' => $restaurants
    ], JSON_NUMERIC_CHECK);

} catch (Firebase\JWT\ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token has expired']);
} catch (Firebase\JWT\SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token signature']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

exit;