<?php
require_once __DIR__ . '/db.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
}

try {
    $pdo = getDbConnection();
    initDatabase($pdo);

    $url = 'https://dummyjson.com/products?limit=0';
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'header'  => "User-Agent: PHP-Seed-Script\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new Exception("Gagal mengunduh data dari $url");
    }

    $json = json_decode($raw, true);
    if (!isset($json['products']) || !is_array($json['products'])) {
        throw new Exception("Format data produk tidak valid");
    }

    $products = $json['products'];
    $stmt = $pdo->prepare("
        INSERT INTO products (
            id, title, description, category, price, discountPercentage, rating, stock,
            tags, brand, sku, weight, dimensions, warrantyInformation, shippingInformation,
            availabilityStatus, reviews, returnPolicy, minimumOrderQuantity, images, thumbnail
        ) VALUES (
            :id, :title, :description, :category, :price, :discountPercentage, :rating, :stock,
            :tags, :brand, :sku, :weight, :dimensions, :warrantyInformation, :shippingInformation,
            :availabilityStatus, :reviews, :returnPolicy, :minimumOrderQuantity, :images, :thumbnail
        ) ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            category = VALUES(category),
            price = VALUES(price),
            discountPercentage = VALUES(discountPercentage),
            rating = VALUES(rating),
            stock = VALUES(stock),
            tags = VALUES(tags),
            brand = VALUES(brand),
            sku = VALUES(sku),
            weight = VALUES(weight),
            dimensions = VALUES(dimensions),
            warrantyInformation = VALUES(warrantyInformation),
            shippingInformation = VALUES(shippingInformation),
            availabilityStatus = VALUES(availabilityStatus),
            reviews = VALUES(reviews),
            returnPolicy = VALUES(returnPolicy),
            minimumOrderQuantity = VALUES(minimumOrderQuantity),
            images = VALUES(images),
            thumbnail = VALUES(thumbnail)
    ");

    $pdo->beginTransaction();
    $count = 0;

    foreach ($products as $p) {
        $stmt->execute([
            ':id'                   => $p['id'] ?? null,
            ':title'                => $p['title'] ?? '',
            ':description'          => $p['description'] ?? '',
            ':category'             => $p['category'] ?? 'smartphones',
            ':price'                => $p['price'] ?? 0.0,
            ':discountPercentage'   => $p['discountPercentage'] ?? 0.0,
            ':rating'               => $p['rating'] ?? 0.0,
            ':stock'                => $p['stock'] ?? 0,
            ':tags'                 => isset($p['tags']) ? json_encode($p['tags']) : '[]',
            ':brand'                => $p['brand'] ?? null,
            ':sku'                  => $p['sku'] ?? null,
            ':weight'               => $p['weight'] ?? null,
            ':dimensions'           => isset($p['dimensions']) ? json_encode($p['dimensions']) : null,
            ':warrantyInformation'  => $p['warrantyInformation'] ?? null,
            ':shippingInformation'  => $p['shippingInformation'] ?? null,
            ':availabilityStatus'   => $p['availabilityStatus'] ?? null,
            ':reviews'              => isset($p['reviews']) ? json_encode($p['reviews']) : '[]',
            ':returnPolicy'         => $p['returnPolicy'] ?? null,
            ':minimumOrderQuantity' => $p['minimumOrderQuantity'] ?? 1,
            ':images'               => isset($p['images']) ? json_encode($p['images']) : '[]',
            ':thumbnail'            => $p['thumbnail'] ?? '',
        ]);
        $count++;
    }

    $pdo->commit();

    $response = [
        'status'  => 'success',
        'message' => "Berhasil mengimpor $count produk dari DummyJSON ke database MySQL.",
        'total'   => $count,
    ];

    if ($isCli) {
        echo "SUCCESS: Berhasil mengimpor $count produk ke MySQL database.\n";
    } else {
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($isCli) {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => $e->getMessage(),
        ], JSON_PRETTY_PRINT);
    }
}
