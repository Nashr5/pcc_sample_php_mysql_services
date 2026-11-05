<?php

function getDbConnection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: 'mysql';
    $port = getenv('DB_PORT') ?: '3306';
    $db   = getenv('DB_DATABASE') ?: 'app';
    $user = getenv('DB_USERNAME') ?: 'my_user';
    $pass = getenv('DB_PASSWORD') ?: 'userpassword';

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);
    return $pdo;
}

function initDatabase(PDO $pdo): void
{
    $sql = "CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        category VARCHAR(100) NOT NULL DEFAULT 'smartphones',
        price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        discountPercentage DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
        rating DECIMAL(3, 2) NOT NULL DEFAULT 0.00,
        stock INT NOT NULL DEFAULT 0,
        tags JSON,
        brand VARCHAR(100) DEFAULT NULL,
        sku VARCHAR(100) DEFAULT NULL,
        weight DECIMAL(8, 2) DEFAULT NULL,
        dimensions JSON DEFAULT NULL,
        warrantyInformation VARCHAR(255) DEFAULT NULL,
        shippingInformation VARCHAR(255) DEFAULT NULL,
        availabilityStatus VARCHAR(100) DEFAULT NULL,
        reviews JSON DEFAULT NULL,
        returnPolicy VARCHAR(255) DEFAULT NULL,
        minimumOrderQuantity INT DEFAULT 1,
        images JSON DEFAULT NULL,
        thumbnail TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql);
}

function formatProduct(array $row): array
{
    $tags = [];
    if (!empty($row['tags'])) {
        $tags = is_string($row['tags']) ? json_decode($row['tags'], true) : $row['tags'];
        if (!is_array($tags)) $tags = [];
    }

    $dimensions = null;
    if (!empty($row['dimensions'])) {
        $dimensions = is_string($row['dimensions']) ? json_decode($row['dimensions'], true) : $row['dimensions'];
    }
    if (!$dimensions || !is_array($dimensions)) {
        $dimensions = [
            'width'  => 0.0,
            'height' => 0.0,
            'depth'  => 0.0,
        ];
    } else {
        $dimensions = [
            'width'  => isset($dimensions['width']) ? (float)$dimensions['width'] : 0.0,
            'height' => isset($dimensions['height']) ? (float)$dimensions['height'] : 0.0,
            'depth'  => isset($dimensions['depth']) ? (float)$dimensions['depth'] : 0.0,
        ];
    }

    $reviews = [];
    if (!empty($row['reviews'])) {
        $reviews = is_string($row['reviews']) ? json_decode($row['reviews'], true) : $row['reviews'];
        if (!is_array($reviews)) $reviews = [];
    }

    $images = [];
    if (!empty($row['images'])) {
        $images = is_string($row['images']) ? json_decode($row['images'], true) : $row['images'];
        if (!is_array($images)) $images = [];
    }
    if (empty($images) && !empty($row['thumbnail'])) {
        $images = [$row['thumbnail']];
    }

    return [
        'id'                   => (int)$row['id'],
        'title'                => (string)($row['title'] ?? ''),
        'description'          => (string)($row['description'] ?? ''),
        'category'             => (string)($row['category'] ?? 'smartphones'),
        'price'                => (float)($row['price'] ?? 0.0),
        'discountPercentage'   => (float)($row['discountPercentage'] ?? 0.0),
        'rating'               => (float)($row['rating'] ?? 0.0),
        'stock'                => (int)($row['stock'] ?? 0),
        'tags'                 => array_values($tags),
        'brand'                => $row['brand'] !== null ? (string)$row['brand'] : null,
        'sku'                  => $row['sku'] !== null ? (string)$row['sku'] : null,
        'weight'               => $row['weight'] !== null ? (float)$row['weight'] : null,
        'dimensions'           => $dimensions,
        'warrantyInformation'  => $row['warrantyInformation'] !== null ? (string)$row['warrantyInformation'] : null,
        'shippingInformation'  => $row['shippingInformation'] !== null ? (string)$row['shippingInformation'] : null,
        'availabilityStatus'   => $row['availabilityStatus'] !== null ? (string)$row['availabilityStatus'] : null,
        'reviews'              => array_values($reviews),
        'returnPolicy'         => $row['returnPolicy'] !== null ? (string)$row['returnPolicy'] : null,
        'minimumOrderQuantity' => $row['minimumOrderQuantity'] !== null ? (int)$row['minimumOrderQuantity'] : 1,
        'images'               => array_values($images),
        'thumbnail'            => (string)($row['thumbnail'] ?? ''),
    ];
}
