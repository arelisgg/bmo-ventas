<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';

require_admin();


/*
|--------------------------------------------------------------------------
| Delete Sale / Quote / Proforma
|--------------------------------------------------------------------------
|
| An invoice that still holds stock gives it back first. Photos are
| kept because the made-to-order product may use them.
|
*/

$salesUrl = APP_BASE_URL . '/admin/sales/';

$saleId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    $saleId === false ||
    !verify_csrf_token($_POST['csrf_token'] ?? null) ||
    !sales_tables_exist($pdo)
) {
    header('Location: ' . $salesUrl);
    exit;
}

try {
    $pdo->beginTransaction();

    $statement = $pdo->prepare('SELECT id, doc_type, status FROM sales WHERE id = :id FOR UPDATE');
    $statement->execute(['id' => $saleId]);
    $sale = $statement->fetch();

    if ($sale) {
        if (sale_consumes_stock((string) $sale['doc_type'], (string) $sale['status'])) {
            restore_sale_stock($pdo, $saleId);
        }

        $pdo->prepare('DELETE FROM sales WHERE id = :id')->execute(['id' => $saleId]);
    }

    $pdo->commit();

    header('Location: ' . $salesUrl . '?deleted=1');
    exit;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($exception->getMessage());

    header('Location: ' . APP_BASE_URL . '/admin/sales/view.php?id=' . $saleId . '&error=1');
    exit;
}
