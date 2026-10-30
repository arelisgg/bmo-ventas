<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

require_admin();


if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {
    header(
        'Location: ' .
        APP_BASE_URL .
        '/admin/categories/'
    );

    exit;
}


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


$categoryId =
    filter_var(
        $_POST['category_id']
        ?? null,
        FILTER_VALIDATE_INT
    );

if (!$categoryId) {
    exit(
        'Invalid category.'
    );
}


$statement =
    $pdo->prepare(
        'UPDATE categories
         SET is_coming_soon =
            CASE
                WHEN is_coming_soon = 1
                THEN 0
                ELSE 1
            END
         WHERE id = :id'
    );

$statement->execute([
    'id' =>
        $categoryId,
]);


header(
    'Location: ' .
    APP_BASE_URL .
    '/admin/categories/?status=1'
);

exit;