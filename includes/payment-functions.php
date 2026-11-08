<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Payments and Cash Register
|--------------------------------------------------------------------------
|
| Cada venta guarda sus pagos en sale_payments (database/migrations/
| 011_cash_payments.sql): puede pagarse con varios métodos o en abonos, y
| queda "Pendiente de pago" mientras tenga saldo. Los pagos hechos con la
| caja abierta se cuentan en esa caja; al cerrarla se compara el efectivo
| esperado con el contado.
|
*/

const PAYMENT_TOLERANCE = 0.005;

function payment_tables_exist(PDO $pdo): bool
{
    static $exists = null;

    if ($exists === null) {
        try {
            $pdo->query('SELECT 1 FROM sale_payments LIMIT 1');
            $pdo->query('SELECT 1 FROM cash_sessions LIMIT 1');
            $pdo->query('SELECT 1 FROM cash_movements LIMIT 1');
            $exists = true;
        } catch (PDOException) {
            $exists = false;
        }
    }

    return $exists;
}

function current_admin_id(): ?int
{
    return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
}


/*
|--------------------------------------------------------------------------
| Sale Payments
|--------------------------------------------------------------------------
*/

/**
 * SQL for what a sale has paid so far (abonos), to subtract from the
 * total of pending sales. "0" before migration 011.
 */
function sale_paid_sql(PDO $pdo, string $saleIdColumn): string
{
    return payment_tables_exist($pdo)
        ? '(SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp WHERE sp.sale_id = ' . $saleIdColumn . ')'
        : '0';
}

function sale_payments(PDO $pdo, int $saleId): array
{
    $statement = $pdo->prepare(
        'SELECT p.*, a.name AS admin_name
         FROM sale_payments p
         LEFT JOIN admins a ON a.id = p.admin_id
         WHERE p.sale_id = :sale_id
         ORDER BY p.paid_at, p.id'
    );
    $statement->execute(['sale_id' => $saleId]);

    return $statement->fetchAll();
}

function sale_paid_amount(PDO $pdo, int $saleId): float
{
    $statement = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM sale_payments WHERE sale_id = :sale_id');
    $statement->execute(['sale_id' => $saleId]);

    return round((float) $statement->fetchColumn(), 2);
}

/**
 * Saves a payment, in the open cash register when there is one.
 */
function add_sale_payment(PDO $pdo, int $saleId, string $method, float $amount, string $note = ''): void
{
    if (!isset(sale_payment_methods()[$method])) {
        throw new DomainException('Elige un método de pago válido.');
    }

    if ($amount <= 0) {
        throw new DomainException('El monto del pago debe ser mayor que cero.');
    }

    $session = open_cash_session($pdo);

    $pdo->prepare(
        'INSERT INTO sale_payments (sale_id, cash_session_id, method, amount, note, admin_id, paid_at)
         VALUES (:sale_id, :cash_session_id, :method, :amount, :note, :admin_id, NOW())'
    )->execute([
        'sale_id' => $saleId,
        'cash_session_id' => $session['id'] ?? null,
        'method' => $method,
        'amount' => round($amount, 2),
        'note' => $note !== '' ? mb_substr($note, 0, 190) : null,
        'admin_id' => current_admin_id(),
    ]);
}

/**
 * Sets the sale to paid or pending from what has been paid. Cancelled
 * sales and proformas are left as they are.
 */
function sync_sale_payment_status(PDO $pdo, int $saleId): void
{
    $statement = $pdo->prepare('SELECT total, status, doc_type FROM sales WHERE id = :id');
    $statement->execute(['id' => $saleId]);
    $sale = $statement->fetch();

    if (!$sale || $sale['status'] === 'cancelled' || $sale['doc_type'] !== 'invoice') {
        return;
    }

    $status = sale_paid_amount($pdo, $saleId) + PAYMENT_TOLERANCE >= (float) $sale['total'] ? 'paid' : 'pending';

    if ($status !== $sale['status']) {
        $pdo->prepare('UPDATE sales SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $saleId]);
    }
}

/**
 * Pays whatever is still owed on the sale with one method (used when a
 * sale is saved or marked as paid).
 */
function settle_sale_balance(PDO $pdo, int $saleId, string $method): void
{
    $statement = $pdo->prepare('SELECT total FROM sales WHERE id = :id');
    $statement->execute(['id' => $saleId]);
    $balance = round((float) $statement->fetchColumn() - sale_paid_amount($pdo, $saleId), 2);

    if ($balance > 0) {
        add_sale_payment($pdo, $saleId, $method, $balance);
    }
}

function delete_sale_payment(PDO $pdo, int $saleId, int $paymentId): void
{
    $pdo->prepare('DELETE FROM sale_payments WHERE id = :id AND sale_id = :sale_id')
        ->execute(['id' => $paymentId, 'sale_id' => $saleId]);
}


/*
|--------------------------------------------------------------------------
| Cash Register
|--------------------------------------------------------------------------
*/

function cash_movement_types(): array
{
    return [
        'in' => 'Entrada',
        'out' => 'Salida',
    ];
}

function open_cash_session(PDO $pdo): ?array
{
    if (!payment_tables_exist($pdo)) {
        return null;
    }

    $session = $pdo->query(
        'SELECT s.*, a.name AS opened_by_name
         FROM cash_sessions s
         LEFT JOIN admins a ON a.id = s.opened_by
         WHERE s.closed_at IS NULL
         ORDER BY s.id DESC
         LIMIT 1'
    )->fetch();

    return $session ?: null;
}

function find_cash_session(PDO $pdo, int $sessionId): ?array
{
    $statement = $pdo->prepare(
        'SELECT s.*, o.name AS opened_by_name, c.name AS closed_by_name
         FROM cash_sessions s
         LEFT JOIN admins o ON o.id = s.opened_by
         LEFT JOIN admins c ON c.id = s.closed_by
         WHERE s.id = :id'
    );
    $statement->execute(['id' => $sessionId]);

    return $statement->fetch() ?: null;
}

function open_new_cash_session(PDO $pdo, float $openingAmount): void
{
    if (open_cash_session($pdo) !== null) {
        throw new DomainException('Ya hay una caja abierta.');
    }

    $pdo->prepare(
        'INSERT INTO cash_sessions (opened_at, opened_by, opening_amount)
         VALUES (NOW(), :opened_by, :opening_amount)'
    )->execute([
        'opened_by' => current_admin_id(),
        'opening_amount' => round($openingAmount, 2),
    ]);
}

function add_cash_movement(PDO $pdo, int $sessionId, string $type, float $amount, string $reason): void
{
    if (!isset(cash_movement_types()[$type])) {
        throw new DomainException('Elige entrada o salida.');
    }

    if ($amount <= 0) {
        throw new DomainException('El monto debe ser mayor que cero.');
    }

    if ($reason === '') {
        throw new DomainException('Escribe el motivo.');
    }

    $pdo->prepare(
        'INSERT INTO cash_movements (cash_session_id, type, amount, reason, admin_id, created_at)
         VALUES (:cash_session_id, :type, :amount, :reason, :admin_id, NOW())'
    )->execute([
        'cash_session_id' => $sessionId,
        'type' => $type,
        'amount' => round($amount, 2),
        'reason' => mb_substr($reason, 0, 190),
        'admin_id' => current_admin_id(),
    ]);
}

/**
 * Totals of a cash session: payments by method (cancelled sales left
 * out), cash in/out and the cash that should be in the drawer.
 */
function cash_session_summary(PDO $pdo, array $session): array
{
    $byMethod = array_fill_keys(array_keys(sale_payment_methods()), 0.0);

    $statement = $pdo->prepare(
        'SELECT p.method, SUM(p.amount) AS total
         FROM sale_payments p
         INNER JOIN sales s ON s.id = p.sale_id
         WHERE p.cash_session_id = :id AND s.status <> \'cancelled\'
         GROUP BY p.method'
    );
    $statement->execute(['id' => $session['id']]);

    foreach ($statement->fetchAll() as $row) {
        $byMethod[$row['method']] = round((float) $row['total'], 2);
    }

    $statement = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN type = \'in\' THEN amount END), 0) AS cash_in,
            COALESCE(SUM(CASE WHEN type = \'out\' THEN amount END), 0) AS cash_out
         FROM cash_movements
         WHERE cash_session_id = :id'
    );
    $statement->execute(['id' => $session['id']]);
    $movements = $statement->fetch();

    $opening = (float) $session['opening_amount'];
    $cashIn = (float) $movements['cash_in'];
    $cashOut = (float) $movements['cash_out'];

    return [
        'opening' => $opening,
        'by_method' => $byMethod,
        'sales_total' => round(array_sum($byMethod), 2),
        'cash_in' => $cashIn,
        'cash_out' => $cashOut,
        'expected_cash' => round($opening + ($byMethod['cash'] ?? 0) + $cashIn - $cashOut, 2),
    ];
}

function cash_session_payments(PDO $pdo, int $sessionId): array
{
    $statement = $pdo->prepare(
        'SELECT p.*, s.sale_number, s.customer_name, s.status AS sale_status, a.name AS admin_name
         FROM sale_payments p
         INNER JOIN sales s ON s.id = p.sale_id
         LEFT JOIN admins a ON a.id = p.admin_id
         WHERE p.cash_session_id = :id
         ORDER BY p.paid_at, p.id'
    );
    $statement->execute(['id' => $sessionId]);

    return $statement->fetchAll();
}

function cash_session_movements(PDO $pdo, int $sessionId): array
{
    $statement = $pdo->prepare(
        'SELECT m.*, a.name AS admin_name
         FROM cash_movements m
         LEFT JOIN admins a ON a.id = m.admin_id
         WHERE m.cash_session_id = :id
         ORDER BY m.created_at, m.id'
    );
    $statement->execute(['id' => $sessionId]);

    return $statement->fetchAll();
}

function close_cash_session(PDO $pdo, array $session, float $countedAmount, string $notes): void
{
    $summary = cash_session_summary($pdo, $session);

    $pdo->prepare(
        'UPDATE cash_sessions
         SET closed_at = NOW(), closed_by = :closed_by, expected_amount = :expected,
             counted_amount = :counted, notes = :notes
         WHERE id = :id AND closed_at IS NULL'
    )->execute([
        'closed_by' => current_admin_id(),
        'expected' => $summary['expected_cash'],
        'counted' => round($countedAmount, 2),
        'notes' => $notes !== '' ? $notes : null,
        'id' => $session['id'],
    ]);
}

function recent_cash_sessions(PDO $pdo, int $limit = 30): array
{
    return $pdo->query(
        'SELECT s.*, o.name AS opened_by_name, c.name AS closed_by_name
         FROM cash_sessions s
         LEFT JOIN admins o ON o.id = s.opened_by
         LEFT JOIN admins c ON c.id = s.closed_by
         WHERE s.closed_at IS NOT NULL
         ORDER BY s.closed_at DESC
         LIMIT ' . max(1, $limit)
    )->fetchAll();
}
