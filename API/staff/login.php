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

require_once '../../vendor/autoload.php';
use Firebase\JWT\JWT;

require_once '../../Common/connection.php';
require_once '../../Common/nepali_date.php';  // ← has ad_to_bs() & nepali_date_time()

$JWT_SECRET = $_ENV['JWT_SECRET'] ?? null;
if (empty($JWT_SECRET)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'JWT_SECRET missing']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$role     = trim($input['role'] ?? '');

if (empty($email) || empty($password) || empty($role)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email, password and role required']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT id, restaurant_id, username, email, role, status, password
        FROM users 
        WHERE email = ? AND role = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        exit;
    }

    if ($user['status'] !== 'active') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Account is not active']);
        exit;
    }

    // ────────────────────────────────────────────────
    // Expiry check using BS dates (no AD conversion needed)
    // ────────────────────────────────────────────────
    $expiryInfo = [
        'has_expiry'   => false,
        'expired'      => false,
        'expiry_date'  => null,
        'is_trial'     => false,
        'message'      => null,
    ];

    if ($user['restaurant_id'] !== null && $user['role'] !== 'superadmin') {
        $restStmt = $conn->prepare("
            SELECT expiry_date, is_trial
            FROM restaurants 
            WHERE id = ?
        ");
        $restStmt->bind_param("i", $user['restaurant_id']);
        $restStmt->execute();
        $restResult = $restStmt->get_result();

        if ($row = $restResult->fetch_assoc()) {
            $bs_expiry = trim($row['expiry_date'] ?? '');
            $is_trial  = (bool)$row['is_trial'];

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bs_expiry)) {
                // Get current BS date (YYYY-MM-DD)
                $current_bs_full = nepali_date_time();           // e.g. "2082-10-22 14:35:12"
                $current_bs_date = substr($current_bs_full, 0, 10);  // "2082-10-22"

                // Compare strings directly (YYYY-MM-DD format allows safe comparison)
                $expired = $current_bs_date > $bs_expiry;

                $expiryInfo = [
                    'has_expiry'   => true,
                    'expired'      => $expired,
                    'expiry_date'  => $bs_expiry,
                    'is_trial'     => $is_trial,
                    'message'      => $expired
                        ? ($is_trial ? 'Trial period has expired' : 'Subscription has expired')
                        : null,
                ];
            }
        }
        $restStmt->close();
    }

    // ────────────────────────────────────────────────
    // Create JWT
    // ────────────────────────────────────────────────
    $payload = [
        'iss' => 'restro-app.local',
        'iat' => time(),
        'exp' => time() + (3600 * 12),
        'data' => [
            'user_id'       => (int)$user['id'],
            'restaurant_id' => $user['restaurant_id'] !== null ? (int)$user['restaurant_id'] : null,
            'username'      => $user['username'],
            'email'         => $user['email'],
            'role'          => $user['role']
        ]
    ];

    $jwt = JWT::encode($payload, $JWT_SECRET, 'HS256');

    unset($user['password']);
    unset($user['status']);

    echo json_encode([
        'success'     => true,
        'token'       => $jwt,
        'user'        => $user,
        'expiry_info' => $expiryInfo,
        'expires_in'  => 3600 * 12
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error: ' . $e->getMessage()
    ]);
}