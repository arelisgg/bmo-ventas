<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Catalog Cart -> Order (JSON)
|--------------------------------------------------------------------------
|
| Called by the cart in index.php right before it opens WhatsApp.
| Saves the order (includes/order-functions.php) and answers its number
| so the message says "Pedido P-00012". If anything fails the cart
| still opens WhatsApp without a number.
|
*/

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/order-functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

if (!orders_tables_exist($pdo)) {
    $respond(200, ['ok' => false, 'error' => 'orders_not_ready']);
}

$raw = (string) file_get_contents('php://input', false, null, 0, 65536);
$payload = json_decode($raw, true);

// Honeypot field the cart leaves empty.
if (!is_array($payload) || trim((string) ($payload['website'] ?? '')) !== '') {
    $respond(400, ['ok' => false, 'error' => 'Pedido no válido.']);
}

try {
    $order = create_catalog_order($pdo, $payload, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64));
    $respond(200, ['ok' => true, 'number' => $order['number'], 'total' => $order['total']]);
} catch (DomainException $exception) {
    $respond(422, ['ok' => false, 'error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Catalog order failed: ' . $exception->getMessage());
    $respond(500, ['ok' => false, 'error' => 'No se pudo guardar el pedido.']);
}
