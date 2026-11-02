<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/finance-functions.php';
require_once __DIR__ . '/../sales/_layout.php';

require_admin();

$tablesReady = sales_tables_exist($pdo) && finance_tables_exist($pdo);
$filters = finance_filters_from_request($_GET);
$filterQuery = http_build_query($filters);


/*
|--------------------------------------------------------------------------
| Fill Missing Costs
|--------------------------------------------------------------------------
|
| Copies each product's current cost onto past sale items without cost.
|
*/

if (
    $tablesReady &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'fill_costs'
) {
    $updated = verify_csrf_token($_POST['csrf_token'] ?? null)
        ? fill_sale_item_costs($pdo)
        : 0;

    header(
        'Location: ' . APP_BASE_URL . '/admin/finance/?' .
        $filterQuery . '&filled=' . $updated
    );
    exit;
}

$summary = null;
$months = [];
$productProfit = [];
$expenseCategories = [];

if ($tablesReady) {
    $summary = finance_summary($pdo, $filters);
    $months = finance_monthly($pdo, $filters);
    $productProfit = finance_product_profit($pdo, $filters);
    $expenseCategories = finance_expenses_by_category($pdo, $filters);
}

$categoryLabels = expense_categories();

render_sales_layout_start('Finanzas', 'finance');

?>

<div class="page-header">
    <div>
        <span class="page-eyebrow">FINANZAS</span>
        <h1 class="page-title">Ganancias y gastos</h1>
        <p class="page-description">
            Cuánto vendiste, cuánto te costó, cuánto gastaste y cuánto te quedó.
        </p>
    </div>

    <a class="admin-button" href="<?= e(APP_BASE_URL) ?>/admin/finance/expenses.php">
        + REGISTRAR GASTO
    </a>
</div>

<?php if (!$tablesReady): ?>

    <?php render_finance_migration_notice(); ?>

<?php else: ?>

    <?php if (isset($_GET['filled'])): ?>
        <div class="admin-notice admin-notice-success">
            Se completó el costo de <?= (int) $_GET['filled'] ?> producto(s) vendido(s).
        </div>
    <?php endif; ?>

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
            <a class="admin-button admin-button-outline"
                href="<?= e(APP_BASE_URL) ?>/admin/finance/export.php?<?= e($filterQuery) ?>">EXPORTAR CSV</a>
        </div>
    </form>

    <div class="dashboard-stat-grid finance-stat-grid">
        <article class="dashboard-stat-card">
            <span class="dashboard-stat-label">Ventas</span>
            <strong class="dashboard-stat-value dashboard-stat-money"><?= e(finance_money($summary['revenue'])) ?></strong>
            <span class="dashboard-stat-note">
                <?= $summary['sales_count'] ?> venta(s) · <?= $summary['units'] ?> unidades
            </span>
        </article>
        <article class="dashboard-stat-card">
            <span class="dashboard-stat-label">Costo de lo vendido</span>
            <strong class="dashboard-stat-value dashboard-stat-money"><?= e(finance_money($summary['cost'])) ?></strong>
            <span class="dashboard-stat-note">Costo de los productos</span>
        </article>
        <article class="dashboard-stat-card">
            <span class="dashboard-stat-label">Ganancia bruta</span>
            <strong class="dashboard-stat-value dashboard-stat-money"><?= e(finance_money($summary['gross_profit'])) ?></strong>
            <span class="dashboard-stat-note">Margen <?= e(format_percent($summary['gross_margin'])) ?></span>
        </article>
        <article class="dashboard-stat-card">
            <span class="dashboard-stat-label">Gastos</span>
            <strong class="dashboard-stat-value dashboard-stat-money"><?= e(finance_money($summary['expenses'])) ?></strong>
            <span class="dashboard-stat-note">
                <a href="<?= e(APP_BASE_URL) ?>/admin/finance/expenses.php?<?= e($filterQuery) ?>">Ver gastos →</a>
            </span>
        </article>
        <article class="dashboard-stat-card finance-net-card <?= $summary['net_profit'] < 0 ? 'finance-negative' : '' ?>">
            <span class="dashboard-stat-label">Utilidad neta</span>
            <strong class="dashboard-stat-value dashboard-stat-money"><?= e(finance_money($summary['net_profit'])) ?></strong>
            <span class="dashboard-stat-note">Margen <?= e(format_percent($summary['net_margin'])) ?></span>
        </article>
    </div>

    <?php if ($summary['units_without_cost'] > 0): ?>
        <div class="admin-notice admin-notice-error finance-cost-warning">
            <div>
                <strong><?= $summary['units_without_cost'] ?> unidad(es) vendida(s) no tienen costo</strong>
                (<?= e(finance_money($summary['revenue_without_cost'])) ?> en ventas),
                así que la ganancia sale más alta de lo real. Pon el costo en cada producto
                (Productos → Editar → Costo) y luego pulsa el botón.
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="fill_costs">
                <button class="admin-button admin-button-outline" type="submit">
                    APLICAR COSTOS A VENTAS ANTERIORES
                </button>
            </form>
        </div>
    <?php endif; ?>

    <section class="dashboard-panel finance-panel">
        <div class="dashboard-panel-header">
            <div>
                <span class="page-eyebrow">POR MES</span>
                <h2>Resultado mensual</h2>
            </div>
        </div>
        <div class="admin-table-wrapper">
            <table class="admin-table finance-table">
                <thead>
                    <tr>
                        <th>Mes</th>
                        <th class="num">Ventas</th>
                        <th class="num">Costo</th>
                        <th class="num">Ganancia bruta</th>
                        <th class="num">Gastos</th>
                        <th class="num">Utilidad neta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($months as $month): ?>
                        <tr>
                            <td><?= e(finance_month_label($month['month'])) ?></td>
                            <td class="num"><?= e(finance_money($month['revenue'])) ?></td>
                            <td class="num"><?= e(finance_money($month['cost'])) ?></td>
                            <td class="num"><?= e(finance_money($month['gross_profit'])) ?></td>
                            <td class="num"><?= e(finance_money($month['expenses'])) ?></td>
                            <td class="num <?= $month['net_profit'] < 0 ? 'finance-negative' : '' ?>">
                                <strong><?= e(finance_money($month['net_profit'])) ?></strong>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="finance-columns">
        <section class="dashboard-panel finance-panel">
            <div class="dashboard-panel-header">
                <div>
                    <span class="page-eyebrow">PERIODO SELECCIONADO</span>
                    <h2>Ganancia por producto</h2>
                </div>
            </div>

            <?php if ($productProfit === []): ?>
                <p class="finance-empty">No hay ventas en este periodo.</p>
            <?php else: ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table finance-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="num">Uds.</th>
                                <th class="num">Ventas</th>
                                <th class="num">Ganancia</th>
                                <th class="num">Margen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productProfit as $row): ?>
                                <?php
                                $revenue = (float) $row['revenue'];
                                $hasCost = (int) $row['units_without_cost'] === 0;
                                $profit = $revenue - (float) $row['cost'];
                                ?>
                                <tr>
                                    <td>
                                        <div class="product-name"><?= e($row['product_name']) ?></div>
                                        <div class="product-code"><?= e($row['product_code']) ?></div>
                                    </td>
                                    <td class="num"><?= (int) $row['units'] ?></td>
                                    <td class="num"><?= e(finance_money($revenue)) ?></td>
                                    <td class="num">
                                        <?php if ($hasCost): ?>
                                            <?= e(finance_money($profit)) ?>
                                        <?php else: ?>
                                            <span class="finance-muted">Sin costo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num">
                                        <?= $hasCost && $revenue > 0 ? e(format_percent($profit / $revenue * 100)) : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel finance-panel">
            <div class="dashboard-panel-header">
                <div>
                    <span class="page-eyebrow">PERIODO SELECCIONADO</span>
                    <h2>Gastos por categoría</h2>
                </div>
            </div>

            <?php if ($expenseCategories === []): ?>
                <p class="finance-empty">
                    No hay gastos registrados en este periodo.
                    <a href="<?= e(APP_BASE_URL) ?>/admin/finance/expenses.php">Registrar gasto →</a>
                </p>
            <?php else: ?>
                <ol class="sales-top-list finance-category-list">
                    <?php foreach ($expenseCategories as $row): ?>
                        <li>
                            <span>
                                <strong><?= e($categoryLabels[$row['category']] ?? $row['category']) ?></strong>
                                <small><?= (int) $row['entries'] ?> registro(s)</small>
                            </span>
                            <span><?= e(finance_money($row['total'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </div>

<?php endif; ?>

<?php render_sales_layout_end(); ?>
