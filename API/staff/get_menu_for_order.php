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

// Debug 1: File started
echo json_encode(['debug_step' => '1_file_started', 'time' => microtime(true)]);
flush();

// ────────────────────────────────────────────────
// Dependencies
// ────────────────────────────────────────────────
require_once '../../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Debug 2: Autoload done
echo json_encode(['debug_step' => '2_autoload_done', 'time' => microtime(true)]);
flush();

require_once '../../Common/connection.php';  // brings $conn

// Debug 3: Connection loaded
echo json_encode(['debug_step' => '3_connection_loaded', 'time' => microtime(true)]);
flush();

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

// Debug 4: Secret loaded
echo json_encode(['debug_step' => '4_secret_loaded', 'time' => microtime(true)]);
flush();

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

    // Debug 5: Header extracted
    echo json_encode(['debug_step' => '5_header_extracted', 'time' => microtime(true)]);
    flush();

    // ────────────────────────────────────────────────
    // Validate JWT
    // ────────────────────────────────────────────────
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));

    // Debug 6: JWT decoded
    echo json_encode(['debug_step' => '6_jwt_decoded', 'time' => microtime(true)]);
    flush();

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

    // Debug 7: User validated
    echo json_encode(['debug_step' => '7_user_validated', 'restaurant_id' => $restaurant_id, 'time' => microtime(true)]);
    flush();

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

    // Debug 8: Menu prepare done
    echo json_encode(['debug_step' => '8_menu_prepare_done', 'time' => microtime(true)]);
    flush();

    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();

    // Debug 9: Menu execute done
    echo json_encode(['debug_step' => '9_menu_execute_done', 'time' => microtime(true)]);
    flush();

    $result = $stmt->get_result();
    $menu = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Debug 10: Menu fetch done
    echo json_encode(['debug_step' => '10_menu_fetch_done', 'menu_count' => count($menu), 'time' => microtime(true)]);
    flush();

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

    // Debug 11: Stock prepare done
    echo json_encode(['debug_step' => '11_stock_prepare_done', 'time' => microtime(true)]);
    flush();

    $stock_stmt->bind_param("i", $restaurant_id);
    $stock_stmt->execute();

    // Debug 12: Stock execute done
    echo json_encode(['debug_step' => '12_stock_execute_done', 'time' => microtime(true)]);
    flush();

    $stock_result = $stock_stmt->get_result();

    $stock = [];
    while ($row = $stock_result->fetch_assoc()) {
        $stock[$row['stock_name']] = (int)$row['quantity'];
    }
    $stock_stmt->close();

    // Debug 13: Stock fetch done
    echo json_encode(['debug_step' => '13_stock_fetch_done', 'stock_count' => count($stock), 'time' => microtime(true)]);
    flush();

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