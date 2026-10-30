<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/finance-functions.php';
require_once __DIR__ . '/../sales/_layout.php';

require_admin();

$tablesReady = finance_tables_exist($pdo);
$filters = finance_filters_from_request($_GET);
$filterQuery = http_build_query($filters);
$categories = expense_categories();
$paymentMethods = sale_payment_methods();
$error = '';

$form = [
    'expense_date' => date('Y-m-d'),
    'category' => 'materials',
    'description' => '',
    'amount' => '',
    'payment_method' => 'cash',
    'notes' => '',
];


/*
|--------------------------------------------------------------------------
| Save / Delete
|--------------------------------------------------------------------------
*/

if ($tablesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido.';
    } elseif ($action === 'delete') {
        $expenseId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($expenseId !== false) {
            $pdo->prepare('DELETE FROM expenses WHERE id = :id')->execute(['id' => $expenseId]);
        }

        header('Location: ' . APP_BASE_URL . '/admin/finance/expenses.php?' . $filterQuery . '&deleted=1');
        exit;
    } else {
        foreach (array_keys($form) as $field) {
            $value = $_POST[$field] ?? '';
            $form[$field] = is_string($value) ? trim($value) : '';
        }

        $date = DateTime::createFromFormat('Y-m-d', $form['expense_date']);
        $amount = parse_money_input($form['amount']);

        if ($date === false || $date->format('Y-m-d') !== $form['expense_date']) {
            $error = 'La fecha no es válida.';
        } elseif (!isset($categories[$form['category']])) {
            $error = 'Elige una categoría.';
        } elseif ($form['description'] === '') {
            $error = 'Escribe en qué fue el gasto.';
        } elseif ($amount === null || $amount <= 0) {
            $error = 'Ingresa un monto mayor que cero.';
        } elseif (!isset($paymentMethods[$form['payment_method']])) {
            $error = 'Elige una forma de pago.';
        }

        if ($error === '') {
            $pdo->prepare(
                'INSERT INTO expenses
                    (expense_date, category, description, amount, payment_method, notes, admin_id)
                 VALUES
                    (:expense_date, :category, :description, :amount, :payment_method, :notes, :admin_id)'
            )->execute([
                'expense_date' => $form['expense_date'],
                'category' => $form['category'],
                'description' => mb_substr($form['description'], 0, 255),
                'amount' => number_format($amount, 2, '.', ''),
                'payment_method' => $form['payment_method'],
                'notes' => $form['notes'] !== '' ? mb_substr($form['notes'], 0, 2000) : null,
                'admin_id' => isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null,
            ]);

            header('Location: ' . APP_BASE_URL . '/admin/finance/expenses.php?' . $filterQuery . '&created=1');
            exit;
        }
    }
}


/*
|--------------------------------------------------------------------------
| List
|--------------------------------------------------------------------------
*/

$expenses = [];
$total = 0.0;

if ($tablesReady) {
    $statement = $pdo->prepare(
        'SELECT *
         FROM expenses
         WHERE expense_date BETWEEN :from AND :to
         ORDER BY expense_date DESC, id DESC'
    );
    $statement->execute(['from' => $filters['from'], 'to' => $filters['to']]);
    $expenses = $statement->fetchAll();
    $total = array_sum(array_map(static fn (array $row): float => (float) $row['amount'], $expenses));
}

render_sales_layout_start('Gastos', 'finance');

?>

<div class="page-header">
    <div>
        <span class="page-eyebrow">FINANZAS</span>
        <h1 class="page-title">Gastos</h1>
        <p class="page-description">
            Anota todo lo que pagas para el negocio: materiales, mano de obra, alquiler, envíos, publicidad.
        </p>
    </div>

    <a class="admin-button admin-button-outline" href="<?= e(APP_BASE_URL) ?>/admin/finance/?<?= e($filterQuery) ?>">
        ← VOLVER A FINANZAS
    </a>
</div>

<?php if (!$tablesReady): ?>

    <?php render_finance_migration_notice(); ?>

<?php else: ?>

    <?php if (isset($_GET['created'])): ?>
        <div class="admin-notice admin-notice-success">Gasto registrado.</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="admin-notice admin-notice-success">Gasto eliminado.</div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="admin-notice admin-notice-error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="dashboard-panel finance-panel">
        <div class="dashboard-panel-header">
            <div>
                <span class="page-eyebrow">NUEVO GASTO</span>
                <h2>Registrar gasto</h2>
            </div>
        </div>

        <form class="finance-expense-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-group">
                <label class="form-label" for="expense_date">Fecha</label>
                <input class="form-input" type="date" id="expense_date" name="expense_date"
                    value="<?= e($form['expense_date']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="category">Categoría</label>
                <select class="form-select" id="category" name="category">
                    <?php foreach ($categories as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $form['category'] === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group finance-expense-description">
                <label class="form-label" for="description">Descripción</label>
                <input class="form-input" type="text" id="description" name="description" maxlength="255"
                    value="<?= e($form['description']) ?>" placeholder="Ej.: cuero para 20 pares" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="amount">Monto (<?= e(currency_symbol()) ?>)</label>
                <input class="form-input" type="number" id="amount" name="amount" min="0.01" step="0.01"
                    value="<?= e($form['amount']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="payment_method">Forma de pago</label>
                <select class="form-select" id="payment_method" name="payment_method">
                    <?php foreach ($paymentMethods as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $form['payment_method'] === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group finance-expense-notes">
                <label class="form-label" for="notes">Nota (opcional)</label>
                <input class="form-input" type="text" id="notes" name="notes" maxlength="2000"
                    value="<?= e($form['notes']) ?>">
            </div>
            <div class="finance-expense-actions">
                <button class="admin-button" type="submit">GUARDAR GASTO</button>
            </div>
        </form>
    </section>

    <form class="sales-filters finance-filters" method="get">
        <div class="form-group">
            <label class="form-label" for="from">Desde</label>
            <input class="form-input" type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="to">Hasta</label>
            <input class="form-input" type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
        </div>
        <div class="sales-filter-actions">
            <button class="admin-button" type="submit">FILTRAR</button>
        </div>
    </form>

    <?php if ($expenses === []): ?>
        <section class="empty-state">
            <h2>No hay gastos en este periodo</h2>
            <p>Los gastos que registres arriba aparecerán aquí.</p>
        </section>
    <?php else: ?>
        <div class="admin-table-wrapper">
            <table class="admin-table finance-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Descripción</th>
                        <th>Categoría</th>
                        <th>Pago</th>
                        <th class="num">Monto</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $expense): ?>
                        <tr>
                            <td><?= e(date('d/m/Y', strtotime((string) $expense['expense_date']))) ?></td>
                            <td>
                                <div class="product-name"><?= e($expense['description']) ?></div>
                                <?php if ((string) $expense['notes'] !== ''): ?>
                                    <div class="product-code"><?= e($expense['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($categories[$expense['category']] ?? $expense['category']) ?></td>
                            <td><?= e(sale_option_label($paymentMethods, $expense['payment_method'])) ?></td>
                            <td class="num"><strong><?= e(format_money($expense['amount'])) ?></strong></td>
                            <td class="num">
                                <form method="post" onsubmit="return confirm('¿Eliminar este gasto?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $expense['id'] ?>">
                                    <button class="table-action-link table-action-danger" type="submit">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4"><strong>Total del periodo</strong></td>
                        <td class="num"><strong><?= e(format_money($total)) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php render_sales_layout_end(); ?>
