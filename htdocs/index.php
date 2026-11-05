<?php
require_once __DIR__ . '/db.php';

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = getDbConnection();
    initDatabase($pdo);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Parse request URI and method
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Helper to get JSON input
function getJsonInput(): array
{
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return [];
    }
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

// Helper to respond with JSON
function jsonResponse($data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Helper to get base host URL
function getBaseUrl(): string
{
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
    return "$scheme://$host";
}

// ROUTING
try {
    // 1. Root /
    if ($path === '/' || $path === '') {
        jsonResponse([
            'status'  => 'ok',
            'message' => 'Product API Service is running',
            'endpoints' => [
                'GET /products' => 'List products (params: limit, skip, q, sortBy, order)',
                'GET /products/{id}' => 'Get single product',
                'GET /products/search?q={query}' => 'Search products',
                'GET /products/category/{category}' => 'Get products by category',
                'GET /products/categories' => 'Get categories with URLs',
                'GET /products/category-list' => 'Get list of category slugs',
                'POST /products/add' => 'Add new product',
                'PUT /products/{id}' => 'Update product',
                'PATCH /products/{id}' => 'Patch product',
                'DELETE /products/{id}' => 'Delete product',
            ]
        ]);
    }

    // 2. GET /products/categories
    if ($path === '/products/categories' && $method === 'GET') {
        $stmt = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $baseUrl = getBaseUrl();
        $result = [];
        foreach ($categories as $cat) {
            $name = ucwords(str_replace('-', ' ', $cat));
            $result[] = [
                'slug' => $cat,
                'name' => $name,
                'url'  => "$baseUrl/products/category/" . urlencode($cat),
            ];
        }
        jsonResponse($result);
    }

    // 3. GET /products/category-list
    if ($path === '/products/category-list' && $method === 'GET') {
        $stmt = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        jsonResponse(array_values($categories));
    }

    // 4. GET /products/search
    if ($path === '/products/search' && $method === 'GET') {
        $q = trim($_GET['q'] ?? '');
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
        $skip = isset($_GET['skip']) ? max(0, (int)$_GET['skip']) : 0;

        if ($q === '') {
            $totalStmt = $pdo->query("SELECT COUNT(*) FROM products");
            $total = (int)$totalStmt->fetchColumn();

            $sql = "SELECT * FROM products ORDER BY id ASC";
            if ($limit > 0) {
                $sql .= " LIMIT $limit OFFSET $skip";
            }
            $stmt = $pdo->query($sql);
        } else {
            $searchTerm = '%' . $q . '%';
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) FROM products 
                WHERE title LIKE :q1 
                   OR description LIKE :q2 
                   OR brand LIKE :q3 
                   OR category LIKE :q4 
                   OR tags LIKE :q5
            ");
            $queryParams = [
                ':q1' => $searchTerm,
                ':q2' => $searchTerm,
                ':q3' => $searchTerm,
                ':q4' => $searchTerm,
                ':q5' => $searchTerm,
            ];
            $countStmt->execute($queryParams);
            $total = (int)$countStmt->fetchColumn();

            $sql = "
                SELECT * FROM products 
                WHERE title LIKE :q1 
                   OR description LIKE :q2 
                   OR brand LIKE :q3 
                   OR category LIKE :q4 
                   OR tags LIKE :q5 
                ORDER BY id ASC
            ";
            if ($limit > 0) {
                $sql .= " LIMIT $limit OFFSET $skip";
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($queryParams);
        }

        $rows = $stmt->fetchAll();
        $products = array_map('formatProduct', $rows);

        jsonResponse([
            'products' => $products,
            'total'    => $total,
            'skip'     => $skip,
            'limit'    => $limit > 0 ? $limit : count($products),
        ]);
    }

    // 5. GET /products/category/{category}
    if (preg_match('#^/products/category/([^/]+)$#', $path, $matches) && $method === 'GET') {
        $category = urldecode($matches[1]);
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
        $skip = isset($_GET['skip']) ? max(0, (int)$_GET['skip']) : 0;

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE LOWER(category) = LOWER(:category)");
        $countStmt->execute([':category' => $category]);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT * FROM products WHERE LOWER(category) = LOWER(:category) ORDER BY id ASC";
        if ($limit > 0) {
            $sql .= " LIMIT $limit OFFSET $skip";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':category' => $category]);

        $rows = $stmt->fetchAll();
        $products = array_map('formatProduct', $rows);

        jsonResponse([
            'products' => $products,
            'total'    => $total,
            'skip'     => $skip,
            'limit'    => $limit > 0 ? $limit : count($products),
        ]);
    }

    // 6. POST /products/add or POST /products (CREATE)
    if (($path === '/products/add' || $path === '/products') && $method === 'POST') {
        $body = getJsonInput();

        $title = trim($body['title'] ?? '');
        if ($title === '') {
            jsonResponse(['message' => 'Product title is required'], 400);
        }

        $description = $body['description'] ?? '';
        $category = $body['category'] ?? 'smartphones';
        $price = isset($body['price']) ? (float)$body['price'] : 0.0;
        $discountPercentage = isset($body['discountPercentage']) ? (float)$body['discountPercentage'] : 0.0;
        $rating = isset($body['rating']) ? (float)$body['rating'] : 0.0;
        $stock = isset($body['stock']) ? (int)$body['stock'] : 0;
        $brand = !empty($body['brand']) ? (string)$body['brand'] : null;
        $sku = !empty($body['sku']) ? (string)$body['sku'] : null;
        $weight = isset($body['weight']) ? (float)$body['weight'] : null;
        $warrantyInformation = $body['warrantyInformation'] ?? null;
        $shippingInformation = $body['shippingInformation'] ?? null;
        $availabilityStatus = $body['availabilityStatus'] ?? ($stock > 0 ? 'In Stock' : 'Out of Stock');
        $returnPolicy = $body['returnPolicy'] ?? null;
        $minimumOrderQuantity = isset($body['minimumOrderQuantity']) ? (int)$body['minimumOrderQuantity'] : 1;
        $thumbnail = $body['thumbnail'] ?? '';

        $tags = isset($body['tags']) && is_array($body['tags']) ? json_encode($body['tags']) : '[]';
        $dimensions = isset($body['dimensions']) && is_array($body['dimensions']) ? json_encode($body['dimensions']) : null;
        $reviews = isset($body['reviews']) && is_array($body['reviews']) ? json_encode($body['reviews']) : '[]';
        $images = isset($body['images']) && is_array($body['images']) ? json_encode($body['images']) : json_encode(!empty($thumbnail) ? [$thumbnail] : []);

        $stmt = $pdo->prepare("
            INSERT INTO products (
                title, description, category, price, discountPercentage, rating, stock,
                tags, brand, sku, weight, dimensions, warrantyInformation, shippingInformation,
                availabilityStatus, reviews, returnPolicy, minimumOrderQuantity, images, thumbnail
            ) VALUES (
                :title, :description, :category, :price, :discountPercentage, :rating, :stock,
                :tags, :brand, :sku, :weight, :dimensions, :warrantyInformation, :shippingInformation,
                :availabilityStatus, :reviews, :returnPolicy, :minimumOrderQuantity, :images, :thumbnail
            )
        ");

        $stmt->execute([
            ':title'                => $title,
            ':description'          => $description,
            ':category'             => $category,
            ':price'                => $price,
            ':discountPercentage'   => $discountPercentage,
            ':rating'               => $rating,
            ':stock'                => $stock,
            ':tags'                 => $tags,
            ':brand'                => $brand,
            ':sku'                  => $sku,
            ':weight'               => $weight,
            ':dimensions'           => $dimensions,
            ':warrantyInformation'  => $warrantyInformation,
            ':shippingInformation'  => $shippingInformation,
            ':availabilityStatus'   => $availabilityStatus,
            ':reviews'              => $reviews,
            ':returnPolicy'         => $returnPolicy,
            ':minimumOrderQuantity' => $minimumOrderQuantity,
            ':images'               => $images,
            ':thumbnail'            => $thumbnail,
        ]);

        $newId = (int)$pdo->lastInsertId();
        $fetchStmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
        $fetchStmt->execute([':id' => $newId]);
        $created = $fetchStmt->fetch();

        jsonResponse(formatProduct($created), 201);
    }

    // 7. Routes with product ID: /products/{id}
    if (preg_match('#^/products/(\d+)$#', $path, $matches)) {
        $id = (int)$matches[1];

        // First check if product exists
        $fetchStmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
        $fetchStmt->execute([':id' => $id]);
        $existing = $fetchStmt->fetch();

        if (!$existing) {
            jsonResponse(['message' => "Product with id '$id' not found"], 404);
        }

        // 7a. GET /products/{id} (READ Single)
        if ($method === 'GET') {
            jsonResponse(formatProduct($existing));
        }

        // 7b. PUT /products/{id} or PATCH /products/{id} (UPDATE)
        if ($method === 'PUT' || $method === 'PATCH') {
            $body = getJsonInput();
            if (empty($body)) {
                jsonResponse(formatProduct($existing));
            }

            // Allowed fields mapping
            $fields = [
                'title', 'description', 'category', 'price', 'discountPercentage',
                'rating', 'stock', 'brand', 'sku', 'weight', 'warrantyInformation',
                'shippingInformation', 'availabilityStatus', 'returnPolicy',
                'minimumOrderQuantity', 'thumbnail'
            ];

            $updates = [];
            $params = [':id' => $id];

            foreach ($fields as $field) {
                if (array_key_exists($field, $body)) {
                    $updates[] = "$field = :$field";
                    $params[":$field"] = $body[$field];
                }
            }

            // Handle JSON fields
            if (array_key_exists('tags', $body)) {
                $updates[] = "tags = :tags";
                $params[':tags'] = is_array($body['tags']) ? json_encode($body['tags']) : $body['tags'];
            }
            if (array_key_exists('dimensions', $body)) {
                $updates[] = "dimensions = :dimensions";
                $params[':dimensions'] = is_array($body['dimensions']) ? json_encode($body['dimensions']) : $body['dimensions'];
            }
            if (array_key_exists('reviews', $body)) {
                $updates[] = "reviews = :reviews";
                $params[':reviews'] = is_array($body['reviews']) ? json_encode($body['reviews']) : $body['reviews'];
            }
            if (array_key_exists('images', $body)) {
                $updates[] = "images = :images";
                $params[':images'] = is_array($body['images']) ? json_encode($body['images']) : $body['images'];
            }

            if (!empty($updates)) {
                $sql = "UPDATE products SET " . implode(', ', $updates) . " WHERE id = :id";
                $updateStmt = $pdo->prepare($sql);
                $updateStmt->execute($params);

                // Re-fetch updated product
                $fetchStmt->execute([':id' => $id]);
                $existing = $fetchStmt->fetch();
            }

            jsonResponse(formatProduct($existing));
        }

        // 7c. DELETE /products/{id} (DELETE)
        if ($method === 'DELETE') {
            $deleteStmt = $pdo->prepare("DELETE FROM products WHERE id = :id");
            $deleteStmt->execute([':id' => $id]);

            $formatted = formatProduct($existing);
            $formatted['isDeleted'] = true;
            $formatted['deletedOn'] = gmdate('Y-m-d\TH:i:s.v\Z');

            jsonResponse($formatted);
        }

        jsonResponse(['message' => "Method $method not allowed on /products/$id"], 405);
    }

    // 8. GET /products (READ List with filters, search, sort, pagination)
    if ($path === '/products' && $method === 'GET') {
        $q = trim($_GET['q'] ?? '');
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
        $skip = isset($_GET['skip']) ? max(0, (int)$_GET['skip']) : 0;
        $sortBy = $_GET['sortBy'] ?? 'id';
        $order = strtolower($_GET['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

        // Allowed sort columns
        $allowedSort = ['id', 'title', 'price', 'rating', 'stock', 'discountPercentage', 'category'];
        if (!in_array($sortBy, $allowedSort)) {
            $sortBy = 'id';
        }

        $whereClause = "";
        $params = [];

        if ($q !== '') {
            $whereClause = "WHERE title LIKE :q1 OR description LIKE :q2 OR brand LIKE :q3 OR category LIKE :q4 OR tags LIKE :q5";
            $searchTerm = '%' . $q . '%';
            $params = [
                ':q1' => $searchTerm,
                ':q2' => $searchTerm,
                ':q3' => $searchTerm,
                ':q4' => $searchTerm,
                ':q5' => $searchTerm,
            ];
        }

        // Total count
        $countSql = "SELECT COUNT(*) FROM products $whereClause";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Main select
        $sql = "SELECT * FROM products $whereClause ORDER BY $sortBy $order";
        if ($limit > 0) {
            $sql .= " LIMIT $limit OFFSET $skip";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $products = array_map('formatProduct', $rows);

        jsonResponse([
            'products' => $products,
            'total'    => $total,
            'skip'     => $skip,
            'limit'    => $limit > 0 ? $limit : count($products),
        ]);
    }

    // 404 Route Not Found
    jsonResponse([
        'message' => "Endpoint '$method $path' not found."
    ], 404);

} catch (Exception $e) {
    jsonResponse([
        'error'   => true,
        'message' => $e->getMessage(),
    ], 500);
}
