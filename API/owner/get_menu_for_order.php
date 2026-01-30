<?php
// api/owner/get_menu_for_order.php
// POST - requires restaurant_id in body + verifies ownership

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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
    echo json_encode(['success' => false, 'error' => 'Server configuration error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST allowed']);
    exit;
}

try {
    // 1. Validate JWT - superadmin only
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token required']);
        exit;
    }

    $token = $matches[1];
    $decoded = JWT::decode($token, new Key($JWT_SECRET, 'HS256'));
    $userData = $decoded->data ?? null;

    if (!$userData || !isset($userData->role) || $userData->role !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'superadmin role required']);
        exit;
    }

    $owner_id = (int)($userData->user_id ?? 0);
    if ($owner_id <= 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid user']);
        exit;
    }

    // 2. Get restaurant_id from body
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $restaurant_id = (int)($input['restaurant_id'] ?? 0);

    if ($restaurant_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'restaurant_id required in body']);
        exit;
    }

    // 3. Verify this owner controls this restaurant
    $chain_stmt = $conn->prepare("
        SELECT chain_id 
        FROM users 
        WHERE id = ? AND role = 'superadmin'
    ");
    $chain_stmt->bind_param("i", $owner_id);
    $chain_stmt->execute();
    $chain_res = $chain_stmt->get_result();

    if ($chain_res->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No chain found']);
        exit;
    }

    $chain_id = (int)$chain_res->fetch_assoc()['chain_id'];
    $chain_stmt->close();

    $check_stmt = $conn->prepare("
        SELECT id FROM restaurants 
        WHERE id = ? AND chain_id = ?
    ");
    $check_stmt->bind_param("ii", $restaurant_id, $chain_id);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not own restaurant #' . $restaurant_id]);
        exit;
    }
    $check_stmt->close();

    // 4. Load menu + categories + stock (same as staff)
    $stmt = $conn->prepare("
        SELECT id, item_name, price, description, category 
        FROM menu_items 
        WHERE restaurant_id = ? AND status = 'available' 
        ORDER BY item_name ASC
    ");
    $stmt->bind_param("i", $restaurant_id);
    $stmt->execute();
    $menu = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $categories = array_unique(array_filter(array_column($menu, 'category'), fn($c) => trim($c) !== ''));
    sort($categories);

    $stock_stmt = $conn->prepare("
        SELECT stock_name, quantity FROM stock_inventory WHERE restaurant_id = ?
    ");
    $stock_stmt->bind_param("i", $restaurant_id);
    $stock_stmt->execute();
    $stock_res = $stock_stmt->get_result();
    $stock = [];
    while ($row = $stock_res->fetch_assoc()) {
        $stock[$row['stock_name']] = (int)$row['quantity'];
    }
    $stock_stmt->close();

    echo json_encode([
        'success'    => true,
        'menu'       => $menu,
        'categories' => $categories,
        'stock'      => $stock
    ], JSON_NUMERIC_CHECK);

} catch (Firebase\JWT\ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token expired']);
} catch (Firebase\JWT\SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

exit;