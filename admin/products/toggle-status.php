<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

require_admin();


/*
|--------------------------------------------------------------------------
| POST Only
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {
    header(
        'Location: ' .
        APP_BASE_URL .
        '/admin/products/'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    !verify_csrf_token(
        $_POST['csrf_token']
        ?? null
    )
) {
    http_response_code(403);

    exit(
        'Invalid security token.'
    );
}


/*
|--------------------------------------------------------------------------
| Product ID
|--------------------------------------------------------------------------
*/

$productId =
    filter_var(
        $_POST['product_id']
        ?? null,
        FILTER_VALIDATE_INT
    );

if (!$productId) {
    http_response_code(400);

    exit(
        'Invalid product.'
    );
}


/*
|--------------------------------------------------------------------------
| Current Status
|--------------------------------------------------------------------------
*/

$statement =
    $pdo->prepare(
        'SELECT is_active
         FROM products
         WHERE id = :id
         LIMIT 1'
    );

$statement->execute([
    'id' =>
        $productId,
]);

$product =
    $statement->fetch();

if (!$product) {
    http_response_code(404);

    exit(
        'Product not found.'
    );
}


/*
|--------------------------------------------------------------------------
| Toggle
|--------------------------------------------------------------------------
*/

$newStatus =
    (int) 
    $product['is_active']
    === 1
    ? 0
    : 1;

$update =
    $pdo->prepare(
        'UPDATE products
         SET is_active = :status
         WHERE id = :id'
    );

$update->execute([
    'status' =>
        $newStatus,

    'id' =>
        $productId,
]);


/*
|--------------------------------------------------------------------------
| Redirect
|--------------------------------------------------------------------------
*/

header(
    'Location: ' .
    APP_BASE_URL .
    '/admin/products/?status=1'
);

exit;