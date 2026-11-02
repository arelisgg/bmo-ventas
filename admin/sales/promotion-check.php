<?php

declare(strict_types=1);

/*
| JSON for the sale form: whether the customer already bought, so a
| "solo primera compra" promotion can be offered or not.
| {"previous": true|false|null}  (null = no phone or name to check)
*/

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/promotion-functions.php';

require_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$exclude = filter_var($_GET['exclude'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

echo json_encode([
    'previous' => customer_has_previous_sales(
        $pdo,
        is_string($_GET['phone'] ?? null) ? $_GET['phone'] : '',
        is_string($_GET['name'] ?? null) ? $_GET['name'] : '',
        $exclude !== false ? $exclude : null
    ),
]);
