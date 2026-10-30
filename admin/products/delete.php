<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/product-functions.php';

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
| Product
|--------------------------------------------------------------------------
*/

$productStatement =
    $pdo->prepare(
        'SELECT
            id,
            main_image
         FROM products
         WHERE id = :id
         LIMIT 1'
    );

$productStatement->execute([
    'id' =>
        $productId,
]);

$product =
    $productStatement->fetch();

if (!$product) {
    http_response_code(404);

    exit(
        'Product not found.'
    );
}


/*
|--------------------------------------------------------------------------
| Additional Images
|--------------------------------------------------------------------------
*/

$imageStatement =
    $pdo->prepare(
        'SELECT image_path
         FROM product_images
         WHERE product_id = :product_id'
    );

$imageStatement->execute([
    'product_id' =>
        $productId,
]);

$additionalImages =
    $imageStatement->fetchAll();


/*
|--------------------------------------------------------------------------
| Delete Database Product
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();

    $delete =
        $pdo->prepare(
            'DELETE FROM products
             WHERE id = :id'
        );

    $delete->execute([
        'id' =>
            $productId,
    ]);

    $pdo->commit();

} catch (
    Throwable
    $exception
) {

    if (
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    error_log(
        $exception->getMessage()
    );

    header(
        'Location: ' .
        APP_BASE_URL .
        '/admin/products/?delete_error=1'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Delete Main Image
|--------------------------------------------------------------------------
*/

delete_product_image(
    $product['main_image']
    ?? null
);


/*
|--------------------------------------------------------------------------
| Delete Gallery Images
|--------------------------------------------------------------------------
*/

foreach (
    $additionalImages
    as
    $image
) {
    delete_product_image(
        $image[
            'image_path'
        ]
        ?? null
    );
}


/*
|--------------------------------------------------------------------------
| Redirect
|--------------------------------------------------------------------------
*/

header(
    'Location: ' .
    APP_BASE_URL .
    '/admin/products/?deleted=1'
);

exit;