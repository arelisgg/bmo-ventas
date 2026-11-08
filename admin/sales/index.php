<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/sales-query.php';
require_once __DIR__ . '/../../includes/payment-functions.php';
require_once __DIR__ . '/_layout.php';

require_admin();

$tablesReady = sales_tables_exist($pdo);

$filters = sales_filters_from_request($_GET);

$sales = [];
$summary = [
    'sales_count' => 0,
    'revenue' => 0,
    'pending_amount' => 0,
    'units' => 0,
];
$topProducts = [];

if ($tablesReady) {
    [$where, $parameters] = sales_where_clause($filters);

    /*
    |--------------------------------------------------------------------------
    | Sales List
    |--------------------------------------------------------------------------
    */

    $salesStatement = $pdo->prepare(
        'SELECT
            s.id,
            s.sale_number,
            s.doc_type,
            s.customer_name,
            s.customer_phone,
            s.channel,
            s.payment_method,
            s.status,
            s.total,
            ' . sale_paid_sql($pdo, 's.id') . ' AS paid_amount,
            s.sold_at,
            (
                SELECT COALESCE(SUM(si.quantity), 0)
                FROM sale_items si
                WHERE si.sale_id = s.id
            ) AS units,
            (
                SELECT GROUP_CONCAT(
                    CONCAT(
                        si.product_name,
                        IF(si.size != \'\', CONCAT(\' (\', si.size, \')\'), \'\'),
                        IF(si.source = \'workshop\', \' · encargo\', \'\')
                    )
                    ORDER BY si.id
                    SEPARATOR \', \'
                )
                FROM sale_items si
                WHERE si.sale_id = s.id
            ) AS items_summary
         FROM sales s
         WHERE ' . $where . '
         ORDER BY s.sold_at DESC, s.id DESC
         LIMIT 500'
    );

    $salesStatement->execute($parameters);

    $sales = $salesStatement->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Summary (cancelled sales excluded)
    |--------------------------------------------------------------------------
    */

    $summaryStatement = $pdo->prepare(
        'SELECT
            COUNT(*) AS sales_count,
            COALESCE(SUM(s.total), 0) AS revenue,
            COALESCE(SUM(CASE WHEN s.status = \'pending\' THEN GREATEST(s.total - ' . sale_paid_sql($pdo, 's.id') . ', 0) ELSE 0 END), 0) AS pending_amount,
            COALESCE(SUM((
                SELECT SUM(si.quantity)
                FROM sale_items si
                WHERE si.sale_id = s.id
            )), 0) AS units
         FROM sales s
         WHERE ' . $where . '
            AND s.status != \'cancelled\'
            AND s.doc_type = \'invoice\''
    );

    $summaryStatement->execute($parameters);

    $summary = $summaryStatement->fetch() ?: $summary;

    /*
    |--------------------------------------------------------------------------
    | Best Sellers In Period
    |--------------------------------------------------------------------------
    */

    $topStatement = $pdo->prepare(
        'SELECT
            si.product_code,
            si.product_name,
            SUM(si.quantity) AS units,
            SUM(si.line_total) AS revenue
         FROM sale_items si
         INNER JOIN sales s
            ON s.id = si.sale_id
         WHERE ' . $where . '
            AND s.status != \'cancelled\'
            AND s.doc_type = \'invoice\'
         GROUP BY si.product_code, si.product_name
         ORDER BY units DESC, revenue DESC
         LIMIT 5'
    );

    $topStatement->execute($parameters);

    $topProducts = $topStatement->fetchAll();
}

$salesCount = (int) $summary['sales_count'];
$averageTicket = $salesCount > 0
    ? (float) $summary['revenue'] / $salesCount
    : 0;

$exportUrl =
    APP_BASE_URL .
    '/admin/sales/export.php?' .
    http_build_query($filters);

$statusLabels = sale_statuses();
$channelLabels = sale_channels();
$paymentLabels = sale_payment_methods();
$docTypeLabels = sale_doc_types();

render_sales_layout_start('Ventas');
?>
<div class="page-header">
    <div>
        <span class="page-eyebrow">
            VENTAS
        </span>
        <h1 class="page-title">
            Ventas
        </h1>
        <p class="page-description">
            Registra ventas, controla lo pendiente de cobro y mira qué se vende más.
        </p>
    </div>
    <?php if ($tablesReady): ?>
        <a class="admin-button" href="<?= e(APP_BASE_URL) ?>/admin/sales/create.php">
            + REGISTRAR VENTA
        </a>
    <?php endif; ?>
</div>

<?php if (!$tablesReady): ?>
    <?php render_sales_migration_notice(); ?>
<?php else: ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="admin-notice admin-notice-success">
            Documento eliminado. Si era una factura, el stock volvió al inventario.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['cancelled'])): ?>
        <div class="admin-notice admin-notice-success">
            Venta cancelada. El stock fue devuelto al inventario.
        </div>
    <?php endif; ?>

    <form class="sales-filters" method="get">
        <div class="form-group">
            <label class="form-label" for="from">Desde</label>
            <input class="form-input" type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="to">Hasta</label>
            <input class="form-input" type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="status">Estado</label>
            <select class="form-select" id="status" name="status">
                <option value="">Todos</option>
                <?php foreach ($statusLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="doc_type">Tipo</label>
            <select class="form-select" id="doc_type" name="doc_type">
                <option value="">Todos</option>
                <?php foreach ($docTypeLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['doc_type'] === $value ? 'selected' : '' ?>><?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="channel">Canal</label>
            <select class="form-select" id="channel" name="channel">
                <option value="">Todos</option>
                <?php foreach ($channelLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['channel'] === $value ? 'selected' : '' ?>><?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group sales-filter-search">
            <label class="form-label" for="q">Buscar</label>
            <input class="form-input" type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
                placeholder="Cliente, teléfono, número o producto">
        </div>
        <div class="sales-filter-actions">
            <button class="admin-button" type="submit">FILTRAR</button>
            <a class="admin-button admin-button-outline" href="<?= e($exportUrl) ?>">EXPORTAR CSV</a>
        </div>
    </form>

    <div class="dashboard-stat-grid">
        <article class="dashboard-stat-card">
            <span class="dashboard-stat-label">Total vendido</span>
            <strong class="dashboard-stat-value dashboard-stat-money"><?= e(format_money($summary['revenue'])) ?></strong>
            <span class="dashboard-stat-note">Solo facturas, sin contar canceladas</span>
        </article>
        <article class="dashboard-stat-card">
            <span class="dashboard-stat-label">Ventas</span>
            <strong class="dashboard-stat-value"><?= $salesCount ?></strong>
            <span class="dashboard-stat-note"><?= (int) $summary['units'] ?> unidades vendidas</span>
        </article>
        <article class="dashboard-stat-card">
            <span class="dashboard-stat-label">Ticket promedio</span>
            <strong class="dashboard-stat-value dashboard-stat-money"><?= e(format_money($averageTicket)) ?></strong>
            <span class="dashboard-stat-note">Por venta</span>
        </article>
        <article class="dashboard-stat-card">
            <span class="dashboard-stat-label">Pendiente de cobro</span>
            <strong class="dashboard-stat-value dashboard-stat-money"><?= e(format_money($summary['pending_amount'])) ?></strong>
            <a href="?<?= e(http_build_query(['status' => 'pending'] + $filters)) ?>">Ver pendientes →</a>
        </article>
    </div>

    <?php if (count($topProducts) > 0): ?>
        <section class="dashboard-panel sales-top-products">
            <div class="dashboard-panel-header">
                <div>
                    <span class="page-eyebrow">PERIODO SELECCIONADO</span>
                    <h2>Más vendidos</h2>
                </div>
            </div>
            <ol class="sales-top-list">
                <?php foreach ($topProducts as $topProduct): ?>
                    <li>
                        <span>
                            <strong><?= e($topProduct['product_name']) ?></strong>
                            <small><?= e($topProduct['product_code']) ?></small>
                        </span>
                        <span><?= (int) $topProduct['units'] ?> u. · <?= e(format_money($topProduct['revenue'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

    <?php if (count($sales) === 0): ?>
        <section class="empty-state">
            <h2>
                No hay ventas en este periodo
            </h2>
            <p>
                Cambia los filtros o registra una venta nueva.
            </p>
            <a class="admin-button" href="<?= e(APP_BASE_URL) ?>/admin/sales/create.php">
                REGISTRAR VENTA
            </a>
        </section>
    <?php else: ?>
        <div class="admin-table-wrapper sales-table-wrapper">
            <table class="admin-table sales-table">
                <thead>
                    <tr>
                        <th>Venta</th>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Productos</th>
                        <th>Canal / Pago</th>
                        <th>Total</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales as $sale): ?>
                        <tr>
                            <td>
                                <div class="product-name"><?= e($sale['sale_number'] ?: sale_number_for_id((int) $sale['id'])) ?>
                                </div>
                                <div class="product-code">
                                    <span class="sale-doc-badge sale-doc-<?= e($sale['doc_type']) ?>">
                                        <?= e(sale_doc_type_titles()[$sale['doc_type']] ?? '') ?>
                                    </span>
                                    <?= (int) $sale['units'] ?> u.
                                </div>
                            </td>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $sale['sold_at']))) ?></td>
                            <td>
                                <div class="product-name"><?= e($sale['customer_name'] ?: '—') ?></div>
                                <div class="product-code"><?= e($sale['customer_phone'] ?? '') ?></div>
                            </td>
                            <td class="sales-items-cell"><?= e($sale['items_summary'] ?? '') ?></td>
                            <td>
                                <div><?= e(sale_option_label($channelLabels, $sale['channel'])) ?></div>
                                <div class="product-code"><?= e(sale_option_label($paymentLabels, $sale['payment_method'])) ?></div>
                            </td>
                            <td><strong><?= e(format_money($sale['total'])) ?></strong></td>
                            <td>
                                <span class="status-badge sale-status-<?= e($sale['status']) ?>">
                                    <?= e(sale_option_label($statusLabels, $sale['status'])) ?>
                                </span>
                                <?php if ($sale['status'] === 'pending' && $sale['doc_type'] === 'invoice' && (float) $sale['paid_amount'] > 0): ?>
                                    <div class="product-code">
                                        Abonado <?= e(format_money($sale['paid_amount'])) ?> · Saldo <?= e(format_money(max(0, (float) $sale['total'] - (float) $sale['paid_amount']))) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="table-action-link"
                                    href="<?= e(APP_BASE_URL) ?>/admin/sales/view.php?id=<?= (int) $sale['id'] ?>">Ver</a>
                                <a class="table-action-link sales-invoice-link" target="_blank" rel="noopener"
                                    href="<?= e(APP_BASE_URL) ?>/admin/sales/invoice.php?id=<?= (int) $sale['id'] ?>&amp;print=1">Imprimir</a>
                                <a class="table-action-link"
                                    href="<?= e(APP_BASE_URL) ?>/admin/sales/create.php?id=<?= (int) $sale['id'] ?>">Editar</a>
                                <form class="sales-delete-form" method="post" action="<?= e(APP_BASE_URL) ?>/admin/sales/delete.php"
                                    onsubmit="return confirm('¿Eliminar <?= e($sale['sale_number'] ?: sale_number_for_id((int) $sale['id'])) ?>? No se puede deshacer.');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $sale['id'] ?>">
                                    <button class="table-action-link table-action-danger" type="submit">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php render_sales_layout_end(); ?>