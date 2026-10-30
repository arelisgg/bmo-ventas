<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/finance-functions.php';

require_admin();

$salesUrl = APP_BASE_URL . '/admin/sales/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $salesUrl);
    exit;
}

$saleId = filter_var(
    $_POST['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$action = $_POST['action'] ?? '';

if (
    $saleId === false ||
    !verify_csrf_token($_POST['csrf_token'] ?? null) ||
    !in_array($action, ['mark_paid', 'cancel', 'to_invoice'], true)
) {
    header('Location: ' . $salesUrl);
    exit;
}

$viewUrl = APP_BASE_URL . '/admin/sales/view.php?id=' . $saleId;

try {
    $pdo->beginTransaction();

    $saleStatement = $pdo->prepare(
        'SELECT id, status, doc_type
         FROM sales
         WHERE id = :id
         FOR UPDATE'
    );

    $saleStatement->execute([
        'id' => $saleId,
    ]);

    $sale = $saleStatement->fetch();

    if (!$sale) {
        $pdo->rollBack();

        header('Location: ' . $salesUrl);
        exit;
    }

    if ($action === 'mark_paid') {
        if ($sale['status'] === 'pending') {
            $pdo->prepare(
                'UPDATE sales
                 SET status = \'paid\'
                 WHERE id = :id'
            )->execute([
                'id' => $saleId,
            ]);
        }

        $pdo->commit();

        header('Location: ' . $viewUrl . '&paid=1');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Quote / Proforma -> Invoice: takes the stock now
    |--------------------------------------------------------------------------
    */

    if ($action === 'to_invoice') {
        if ($sale['doc_type'] !== 'invoice') {
            if ($sale['status'] !== 'cancelled') {
                take_sale_stock($pdo, $saleId);
            }

            $pdo->prepare(
                'UPDATE sales
                 SET doc_type = \'invoice\', sale_number = :sale_number, sold_at = NOW()
                 WHERE id = :id'
            )->execute([
                'sale_number' => sale_number_for('invoice', $saleId),
                'id' => $saleId,
            ]);

            fill_sale_item_costs($pdo, $saleId);
        }

        $pdo->commit();

        header('Location: ' . $viewUrl . '&converted=1');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Cancel: return stock once
    |--------------------------------------------------------------------------
    */

    if ($sale['status'] !== 'cancelled') {
        if (sale_consumes_stock((string) $sale['doc_type'], (string) $sale['status'])) {
            restore_sale_stock($pdo, $saleId);
        }

        $pdo->prepare(
            'UPDATE sales
             SET
                status = \'cancelled\',
                cancelled_at = NOW()
             WHERE id = :id'
        )->execute([
            'id' => $saleId,
        ]);
    }

    $pdo->commit();

    header('Location: ' . $salesUrl . '?cancelled=1');
    exit;
} catch (DomainException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header('Location: ' . $viewUrl . '&nostock=1');
    exit;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($exception->getMessage());

    header('Location: ' . $viewUrl . '&error=1');
    exit;
}
