<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/order-functions.php';
require_once __DIR__ . '/../sales/_layout.php';

require_admin();


/*
|--------------------------------------------------------------------------
| Pedidos (catalog cart orders)
|--------------------------------------------------------------------------
*/

$tablesReady = orders_tables_exist($pdo);
$statuses = order_statuses();
$status = (string) ($_GET['status'] ?? 'open');
$search = trim((string) ($_GET['q'] ?? ''));
$orders = [];
$counts = [];

if ($tablesReady) {
    foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM orders GROUP BY status')->fetchAll() as $row) {
        $counts[$row['status']] = (int) $row['total'];
    }

    $where = [];
    $params = [];

    if ($status === 'open') {
        $where[] = "o.status IN ('new', 'confirmed')";
    } elseif (isset($statuses[$status])) {
        $where[] = 'o.status = :status';
        $params['status'] = $status;
    } else {
        $status = '';
    }

    if ($search !== '') {
        $where[] = '(o.customer_name LIKE :q OR o.customer_phone LIKE :q OR CAST(o.id AS CHAR) = :id
            OR EXISTS (SELECT 1 FROM order_items si WHERE si.order_id = o.id AND (si.product_name LIKE :q OR si.product_code LIKE :q)))';
        $params['q'] = '%' . $search . '%';
        $params['id'] = (string) (int) preg_replace('/\D+/', '', $search);
    }

    $statement = $pdo->prepare(
        'SELECT
            o.*,
            s.sale_number,
            (SELECT GROUP_CONCAT(DISTINCT i.product_name ORDER BY i.id SEPARATOR ", ")
             FROM order_items i WHERE i.order_id = o.id) AS models_summary,
            (SELECT COALESCE(SUM(i.quantity), 0) FROM order_items i WHERE i.order_id = o.id) AS pairs
         FROM orders o
         LEFT JOIN sales s ON s.id = o.sale_id ' .
        ($where !== [] ? 'WHERE ' . implode(' AND ', $where) : '') . '
         ORDER BY o.id DESC
         LIMIT 300'
    );
    $statement->execute($params);
    $orders = $statement->fetchAll();
}

render_sales_layout_start('Pedidos', 'orders');

?>

<div class="page-header">
    <div>
        <span class="page-eyebrow">CATÁLOGO</span>
        <h1 class="page-title">Pedidos</h1>
        <p class="page-description">
            Los pedidos que los clientes arman en el carrito del catálogo. Llegan aquí con su número antes de abrir
            WhatsApp. Confírmalos, conviértelos en venta o mándalos al taller.
        </p>
    </div>
</div>

<?php if (!$tablesReady): ?>

    <?php render_orders_migration_notice(); ?>

<?php else: ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="admin-notice admin-notice-success">Pedido eliminado.</div>
    <?php endif; ?>

    <div class="orders-status-tabs">
        <?php
        $tabs = ['open' => ['Por atender', ($counts['new'] ?? 0) + ($counts['confirmed'] ?? 0)]];

        foreach ($statuses as $value => $label) {
            $tabs[$value] = [$label, $counts[$value] ?? 0];
        }

        $tabs[''] = ['Todos', array_sum($counts)];
        ?>
        <?php foreach ($tabs as $value => [$label, $total]): ?>
            <a class="<?= $status === (string) $value ? 'is-active' : '' ?>"
                href="?status=<?= e((string) $value) ?><?= $search !== '' ? '&amp;q=' . e(rawurlencode($search)) : '' ?>">
                <?= e($label) ?> <span><?= (int) $total ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="sales-filters orders-filters" method="get">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <div class="form-group">
            <label class="form-label" for="q">Buscar</label>
            <input class="form-input" id="q" name="q" type="search" value="<?= e($search) ?>"
                placeholder="Cliente, teléfono, modelo o número">
        </div>
    </form>

    <?php if ($orders === []): ?>
        <section class="empty-state">
            <h2>No hay pedidos aquí</h2>
            <p>Cuando un cliente envíe su carrito desde el catálogo, el pedido aparecerá en esta lista.</p>
        </section>
    <?php else: ?>
        <div class="admin-table-wrapper">
            <table class="admin-table orders-table">
                <thead>
                    <tr>
                        <th>Pedido</th>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Modelos</th>
                        <th>Total estimado</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>
                                <div class="product-name"><?= e(order_number((int) $order['id'])) ?></div>
                                <div class="product-code"><?= (int) $order['pairs'] ?> <?= (int) $order['pairs'] === 1 ? 'par' : 'pares' ?><?= (int) $order['is_wholesale'] === 1 ? ' · mayorista' : '' ?></div>
                            </td>
                            <td><?= e(date('d/m/Y H:i', strtotime((string) $order['created_at']))) ?></td>
                            <td>
                                <div class="product-name"><?= e($order['customer_name'] ?: 'Sin nombre') ?></div>
                                <?php if ((string) $order['customer_phone'] !== ''): ?>
                                    <div class="product-code"><?= e($order['customer_phone']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="sales-items-cell"><?= e($order['models_summary'] ?? '') ?></td>
                            <td><?= e(format_money($order['total'])) ?></td>
                            <td>
                                <span class="status-badge order-status-<?= e($order['status']) ?>">
                                    <?= e($statuses[$order['status']] ?? $order['status']) ?>
                                </span>
                                <?php if ((string) $order['sale_number'] !== ''): ?>
                                    <div class="product-code">Venta <?= e($order['sale_number']) ?></div>
                                <?php endif; ?>
                                <?php if ($order['workshop_order_id'] !== null): ?>
                                    <div class="product-code">Taller PT-<?= e(str_pad((string) $order['workshop_order_id'], 5, '0', STR_PAD_LEFT)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="table-action-link"
                                    href="<?= e(APP_BASE_URL) ?>/admin/orders/view.php?id=<?= (int) $order['id'] ?>">Ver</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php render_sales_layout_end(); ?>
