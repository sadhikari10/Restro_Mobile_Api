<?php
// api/staff/get_menu_for_order.php
// Protected GET endpoint: returns menu items, categories, and stock for ordering
// Requires valid JWT (staff role)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');           // ← Change to your mobile app domain in production
header('Access-Control-Allow-Methods: GET, OPTIONS');
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
use Firebase\JWT\Key;

require_once '../../Common/connection.php';  // brings $conn

// ────────────────────────────────────────────────
// Load JWT secret from .env
// ────────────────────────────────────────────────
$JWT_SECRET = $_ENV['JWT_SECRET'] ?? null;

if (empty($JWT_SECRET)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server configuration error (missing JWT secret)'
    ]);
    exit;
}

// ────────────────────────────────────────────────
// Only GET allowed
// ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Only GET method is allowed'
    ]);
    exit;
}

try {
    // ────────────────────────────────────────────────
    // Extract Bearer Token
    // ────────────────────────────────────────────────
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Authentication token required'
        ]);
        exit;
    }

    $token = $matches[1];

    // ────────────────────────────────────────────────
    // Validate JWT
    // ────────────────────────────────────────────────
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));

    $userData = $decoded->data ?? null;

    if (!$userData || !isset($userData->role) || $userData->role !== 'staff') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Access denied - staff role required'
        ]);
        exit;
    }

    $restaurant_id = (int)($userData->restaurant_id ?? 0);

    if ($restaurant_id <= 0) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Invalid restaurant context'
        ]);
        exit;
    }

    // ────────────────────────────────────────────────
    // Fetch available menu items
    // ────────────────────────────────────────────────
    $stmt = $conn->prepare("
        SELECT 
            id, 
            item_name, 
            price, 
            description, 
            category 
        FROM menu_items 
        WHERE restaurant_id = ? 
          AND status = 'available' 
        ORDER BY item_name ASC
    ");

    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $menu = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ────────────────────────────────────────────────
    // Unique categories (non-empty)
    // ────────────────────────────────────────────────
    $categories = array_unique(
        array_filter(
            array_column($menu, 'category'),
            fn($cat) => !empty(trim($cat))
        )
    );
    sort($categories);

    // ────────────────────────────────────────────────
    // Fetch current stock
    // ────────────────────────────────────────────────
    $stock_stmt = $conn->prepare("
        SELECT stock_name, quantity 
        FROM stock_inventory 
        WHERE restaurant_id = ?
    ");

    $stock_stmt->bind_param("i", $restaurant_id);
    $stock_stmt->execute();
    $stock_result = $stock_stmt->get_result();

    $stock = [];
    while ($row = $stock_result->fetch_assoc()) {
        $stock[$row['stock_name']] = (int)$row['quantity'];
    }
    $stock_stmt->close();

    // ────────────────────────────────────────────────
    // Success response
    // ────────────────────────────────────────────────
    echo json_encode([
        'success'    => true,
        'menu'       => $menu,
        'categories' => $categories,
        'stock'      => $stock
    ], JSON_NUMERIC_CHECK);

} catch (Firebase\JWT\ExpiredException $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Token has expired'
    ]);
} catch (Firebase\JWT\SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid token signature'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error: ' . $e->getMessage()
    ]);
}

exit;