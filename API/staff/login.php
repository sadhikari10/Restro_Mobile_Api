<?php
// api/staff/login.php
// Dedicated JWT login endpoint for mobile app (does NOT use sessions)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');           // ← tighten this later (Flutter origin)
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../vendor/autoload.php';
require_once '../../Common/connection.php';

use \Firebase\JWT\JWT;
$jwt = JWT::encode($payload, $JWT_SECRET, 'HS256');

// IMPORTANT: Define your secret key here or better — load from config/env file
// NEVER commit this to git in plain text!
$JWT_SECRET = 'JGn%m*PZwVc3H9(TxgubLjqCUv)M#$Fkha58Q2BY7I^s4E&NWeKSRfpz+Xt!A6Dr';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$email    = trim($input['email']    ?? '');
$password = $input['password']      ?? '';
$role     = trim($input['role']     ?? '');

if (empty($email) || empty($password) || empty($role)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Email, password and role are required'
    ]);
    exit;
}

try {
    // Fetch user
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
            'error'   => 'Invalid email or role'
        ]);
        exit;
    }

    if ($user['status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Account is not active'
        ]);
        exit;
    }

    // Verify password
    if (!password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Invalid password'
        ]);
        exit;
    }

    // Remove sensitive data before putting in token
    unset($user['password']);
    unset($user['status']);

    // Create JWT payload
    $payload = [
        'iss' => 'your-app-name.com.np',           // issuer
        'iat' => time(),                            // issued at
        'exp' => time() + (3600 * 12),              // expires in 12 hours (adjust as needed)
        'data' => [
            'user_id'       => (int)$user['id'],
            'restaurant_id' => (int)$user['restaurant_id'],
            'username'      => $user['username'],
            'email'         => $user['email'],
            'role'          => $user['role']
        ]
    ];


    // Success response
    echo json_encode([
        'success' => true,
        'token'   => $jwt,
        'user'    => [
            'id'            => $user['id'],
            'restaurant_id' => $user['restaurant_id'],
            'username'      => $user['username'],
            'email'         => $user['email'],
            'role'          => $user['role']
        ],
        'expires_in' => 3600 * 12   // seconds
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error: ' . $e->getMessage()
    ]);
}