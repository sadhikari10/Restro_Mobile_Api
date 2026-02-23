<?php
// api/owner/get_menu_for_order.php
// GET endpoint: Returns menu categories, items and stock for a specific restaurant
// Protected by JWT - only superadmin allowed

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
    echo json_encode(['success' => false, 'error' => 'Server configuration error (JWT_SECRET missing)']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only GET allowed']);
    exit;
}

// ────────────────────────────────────────────────
// Robust token extraction with debug logging
// ────────────────────────────────────────────────
$headers = getallheaders();


$token = null;

// 1. Standard getallheaders()
if (isset($headers['Authorization'])) {
    $token = $headers['Authorization'];
} elseif (isset($headers['authorization'])) {
    $token = $headers['authorization'];
}

// 2. $_SERVER fallbacks
if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $token = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (!$token && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $token = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

// 3. apache_request_headers() if available
if (!$token && function_exists('apache_request_headers')) {
    $apacheHeaders = apache_request_headers();
    if (isset($apacheHeaders['Authorization'])) {
        $token = $apacheHeaders['Authorization'];
    } elseif (isset($apacheHeaders['authorization'])) {
        $token = $apacheHeaders['authorization'];
    }
}

// 4. Final fallback: case-insensitive loop
if (!$token) {
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            $token = $value;
            break;
        }
    }
}

// Extract Bearer token
if ($token && preg_match('/Bearer\s+(.+)/i', $token, $matches)) {
    $token = trim($matches[1]);
} else {
    $token = null;
}

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication token required (header not detected)']);
    exit;
}

// ─── JWT validation ──────────────────────────────────────────────────
try {
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $user = (array)$decoded->data;

    if (!isset($user['role']) || $user['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only owner allowed']);
        exit;
    }

    $user_id = (int)$user['user_id'];

    // ─── Get restaurant_id from query ───────────────────────────────────
    $restaurant_id = isset($_GET['restaurant_id']) ? (int)$_GET['restaurant_id'] : 0;
    if ($restaurant_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'restaurant_id is required in query']);
        exit;
    }

    // Security: verify restaurant exists and get chain_id
    $check = $conn->prepare("
        SELECT chain_id 
        FROM restaurants 
        WHERE id = ?
    ");
    if ($check === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Prepare failed (restaurant check): ' . $conn->error
        ]);
        exit;
    }
    $check->bind_param("i", $restaurant_id);
    $check->execute();
    $res = $check->get_result();

    if ($res->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid restaurant ID']);
        exit;
    }

    $rest_row = $res->fetch_assoc();
    $chain_id = (int)$rest_row['chain_id'];
    $check->close();

    // Security: verify this chain belongs to the current superadmin
    $owner_check = $conn->prepare("
        SELECT id FROM users 
        WHERE id = ? AND chain_id = ? AND role = 'superadmin'
        LIMIT 1
    ");
    if ($owner_check === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Prepare failed (owner check): ' . $conn->error
        ]);
        exit;
    }
    $owner_check->bind_param("ii", $user_id, $chain_id);
    $owner_check->execute();
    $owner_res = $owner_check->get_result();

    if ($owner_res->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized - restaurant not in your chain']);
        exit;
    }
    $owner_check->close();

    // ─── Fetch categories ────────────────────────────────────────────────
    $cat_stmt = $conn->prepare("
        SELECT DISTINCT category 
        FROM menu_items 
        WHERE restaurant_id = ? AND status = 'available'
        ORDER BY category
    ");
    if ($cat_stmt === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Prepare failed (categories): ' . $conn->error
        ]);
        exit;
    }
    $cat_stmt->bind_param("i", $restaurant_id);
    $cat_stmt->execute();
    $cat_result = $cat_stmt->get_result();
    $categories = [];
    while ($row = $cat_result->fetch_assoc()) {
        $categories[] = $row['category'];
    }
    $cat_stmt->close();

    // ─── Fetch menu items ────────────────────────────────────────────────
    $item_stmt = $conn->prepare("
        SELECT id, item_name, price, description, category
        FROM menu_items 
        WHERE restaurant_id = ? AND status = 'available'
        ORDER BY category, item_name
    ");
    if ($item_stmt === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Prepare failed (menu items): ' . $conn->error
        ]);
        exit;
    }
    $item_stmt->bind_param("i", $restaurant_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    $menu = [];
    while ($row = $item_result->fetch_assoc()) {
        $menu[] = $row;
    }
    $item_stmt->close();

    // ─── Fetch stock (by item_name) ──────────────────────────────────────
    $stock_stmt = $conn->prepare("
        SELECT stock_name, quantity 
        FROM stock_inventory 
        WHERE restaurant_id = ?
    ");
    if ($stock_stmt === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Prepare failed (stock): ' . $conn->error
        ]);
        exit;
    }
    $stock_stmt->bind_param("i", $restaurant_id);
    $stock_stmt->execute();
    $stock_result = $stock_stmt->get_result();
    $stock = [];
    while ($row = $stock_result->fetch_assoc()) {
        $stock[$row['stock_name']] = (int)$row['quantity'];
    }
    $stock_stmt->close();

    // ─── Success response ────────────────────────────────────────────────
    echo json_encode([
        'success'    => true,
        'categories' => $categories,
        'menu'       => $menu,
        'stock'      => $stock
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