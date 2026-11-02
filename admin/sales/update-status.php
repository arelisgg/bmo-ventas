<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/finance-functions.php';
require_once __DIR__ . '/../../includes/payment-functions.php';

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
    !in_array($action, ['mark_paid', 'cancel', 'to_invoice', 'add_payment', 'delete_payment'], true)
) {
    header('Location: ' . $salesUrl);
    exit;
}

$viewUrl = APP_BASE_URL . '/admin/sales/view.php?id=' . $saleId;

try {
    $pdo->beginTransaction();

    $saleStatement = $pdo->prepare(
        'SELECT id, status, doc_type, payment_method
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

    /*
    |--------------------------------------------------------------------------
    | Payments: add an abono / part of a mixed payment, or remove one
    |--------------------------------------------------------------------------
    */

    if ($action === 'add_payment' || $action === 'delete_payment') {
        if (!payment_tables_exist($pdo) || $sale['status'] === 'cancelled' || $sale['doc_type'] !== 'invoice') {
            $pdo->rollBack();

            header('Location: ' . $viewUrl);
            exit;
        }

        if ($action === 'add_payment') {
            $amount = parse_money_input($_POST['amount'] ?? '');

            if ($amount === null || $amount <= 0) {
                throw new DomainException('Escribe un monto válido.');
            }

            add_sale_payment(
                $pdo,
                $saleId,
                (string) ($_POST['method'] ?? ''),
                $amount,
                trim((string) ($_POST['note'] ?? ''))
            );
        } else {
            if (!admin_is_owner()) {
                $pdo->rollBack();

                header('Location: ' . $viewUrl);
                exit;
            }

            delete_sale_payment($pdo, $saleId, (int) ($_POST['payment_id'] ?? 0));
        }

        sync_sale_payment_status($pdo, $saleId);

        $pdo->commit();

        header('Location: ' . $viewUrl . ($action === 'add_payment' ? '&payment=1' : '&payment_deleted=1'));
        exit;
    }

    if ($action === 'mark_paid') {
        if ($sale['status'] === 'pending') {
            if (payment_tables_exist($pdo)) {
                settle_sale_balance($pdo, $saleId, (string) $sale['payment_method']);
            }

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

            if ($sale['status'] === 'paid' && payment_tables_exist($pdo)) {
                settle_sale_balance($pdo, $saleId, (string) $sale['payment_method']);
            }
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

    if ($action === 'add_payment') {
        header('Location: ' . $viewUrl . '&payment_error=' . rawurlencode($exception->getMessage()));
        exit;
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
