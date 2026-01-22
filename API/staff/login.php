<?php
// API/staff/login.php
// Dedicated JWT login endpoint for mobile app (no sessions)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');           // Tighten this in production
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ────────────────────────────────────────────────
// Dependencies
// ────────────────────────────────────────────────
require_once '../../vendor/autoload.php';
use Firebase\JWT\JWT;

require_once '../../Common/connection.php';  // brings $conn

// ────────────────────────────────────────────────
// Load JWT secret from .env (with fallback for local testing)
// ────────────────────────────────────────────────
$JWT_SECRET = $_ENV['JWT_SECRET'] ?? null;

if (empty($JWT_SECRET)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'JWT_SECRET is not configured in .env file'
    ]);
    exit;
}

// ────────────────────────────────────────────────
// Only POST allowed
// ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Only POST method is allowed'
    ]);
    exit;
}

// ────────────────────────────────────────────────
// Read JSON input
// ────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$email    = trim($input['email']    ?? '');
$password = $input['password']      ?? '';
$role     = trim($input['role']     ?? '');

if (empty($email) || empty($password) || empty($role)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Email, password and role are required'
    ]);
    exit;
}

try {
    // ────────────────────────────────────────────────
    // Find user
    // ────────────────────────────────────────────────
    $stmt = $conn->prepare("
        SELECT 
            id,
            restaurant_id,
            username,
            email,
            role,
            status,
            password
        FROM users 
        WHERE email = ? AND role = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid email or role'
        ]);
        exit;
    }

    if ($user['status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Account is not active'
        ]);
        exit;
    }

    // ────────────────────────────────────────────────
    // Verify password
    // ────────────────────────────────────────────────
    if (!password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid password'
        ]);
        exit;
    }

    // ────────────────────────────────────────────────
    // SUCCESS → Create JWT
    // ────────────────────────────────────────────────
    $payload = [
        'iss' => 'restro-app.local',                // issuer
        'iat' => time(),                            // issued at
        'exp' => time() + (3600 * 12),              // 12 hours expiry
        'data' => [
            'user_id'       => (int)$user['id'],
            'restaurant_id' => (int)$user['restaurant_id'],
            'username'      => $user['username'],
            'email'         => $user['email'],
            'role'          => $user['role']
        ]
    ];

    $jwt = JWT::encode($payload, $JWT_SECRET, 'HS256');

    // Remove sensitive fields before sending user data
    unset($user['password']);
    unset($user['status']);

    // ────────────────────────────────────────────────
    // Final success response
    // ────────────────────────────────────────────────
    echo json_encode([
        'success'    => true,
        'token'      => $jwt,
        'user'       => $user,
        'expires_in' => 3600 * 12   // in seconds
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error: ' . $e->getMessage()
    ]);
}