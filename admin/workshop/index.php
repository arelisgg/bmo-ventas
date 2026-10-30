<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/workshop-functions.php';
require_once __DIR__ . '/../sales/_layout.php';

require_admin();

$tablesReady = workshop_tables_exist($pdo);
$statuses = workshop_statuses();
$status = (string) ($_GET['status'] ?? 'open');
$orders = [];

if ($tablesReady) {
    $where = '';
    $params = [];

    if ($status === 'open') {
        $where = "WHERE o.status IN ('pending', 'in_progress')";
    } elseif (isset($statuses[$status])) {
        $where = 'WHERE o.status = :status';
        $params['status'] = $status;
    } else {
        $status = '';
    }

    $statement = $pdo->prepare(
        "SELECT
            o.*,
            s.sale_number,
            (SELECT GROUP_CONCAT(m.product_name ORDER BY m.sort_order SEPARATOR ', ')
             FROM workshop_order_models m WHERE m.order_id = o.id) AS models_summary,
            (SELECT COALESCE(SUM(l.quantity), 0)
             FROM workshop_order_lines l
             INNER JOIN workshop_order_models m ON m.id = l.model_id
             WHERE m.order_id = o.id) AS pairs
         FROM workshop_orders o
         LEFT JOIN sales s ON s.id = o.sale_id
         $where
         ORDER BY o.id DESC
         LIMIT 300"
    );
    $statement->execute($params);
    $orders = $statement->fetchAll();
}

render_sales_layout_start('Pedidos al taller', 'workshop');

?>

<div class="page-header">
    <div>
        <span class="page-eyebrow">TALLER</span>
        <h1 class="page-title">Pedidos al taller</h1>
        <p class="page-description">
            Arma la orden con la foto de cada modelo y los pares por color y talla, e imprímela para el taller.
        </p>
    </div>
    <?php if ($tablesReady): ?>
        <a class="admin-button" href="<?= e(APP_BASE_URL) ?>/admin/workshop/create.php">+ NUEVO PEDIDO</a>
    <?php endif; ?>
</div>

<?php if (!$tablesReady): ?>

    <?php render_workshop_migration_notice(); ?>

<?php else: ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="admin-notice admin-notice-success">Pedido eliminado.</div>
    <?php endif; ?>

    <form class="sales-filters workshop-filters" method="get">
        <div class="form-group">
            <label class="form-label" for="status">Estado</label>
            <select class="form-select" id="status" name="status" onchange="this.form.submit()">
                <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Pendientes y en el taller</option>
                <option value="" <?= $status === '' ? 'selected' : '' ?>>Todos</option>
                <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($orders === []): ?>
        <section class="empty-state">
            <h2>No hay pedidos aquí</h2>
            <p>Crea uno nuevo, o desde una venta usa «CREAR PEDIDO AL TALLER».</p>
            <a class="admin-button" href="<?= e(APP_BASE_URL) ?>/admin/workshop/create.php">NUEVO PEDIDO</a>
        </section>
    <?php else: ?>
        <div class="admin-table-wrapper">
            <table class="admin-table workshop-table">
                <thead>
                    <tr>
                        <th>Pedido</th>
                        <th>Fecha</th>
                        <th>Cliente / referencia</th>
                        <th>Modelos</th>
                        <th>Entrega</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>
                                <div class="product-name"><?= e(workshop_order_number((int) $order['id'])) ?></div>
                                <div class="product-code"><?= (int) $order['pairs'] ?> pares</div>
                            </td>
                            <td><?= e(date('d/m/Y', strtotime((string) $order['created_at']))) ?></td>
                            <td>
                                <div class="product-name"><?= e($order['reference'] ?: '—') ?></div>
                                <?php if ((string) $order['sale_number'] !== ''): ?>
                                    <div class="product-code">Venta <?= e($order['sale_number']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="sales-items-cell"><?= e($order['models_summary'] ?? '') ?></td>
                            <td>
                                <?= (string) $order['due_date'] !== ''
                                    ? e(date('d/m/Y', strtotime((string) $order['due_date'])))
                                    : '—' ?>
                            </td>
                            <td>
                                <span class="status-badge workshop-status-<?= e($order['status']) ?>">
                                    <?= e($statuses[$order['status']] ?? $order['status']) ?>
                                </span>
                            </td>
                            <td>
                                <a class="table-action-link"
                                    href="<?= e(APP_BASE_URL) ?>/admin/workshop/view.php?id=<?= (int) $order['id'] ?>">Ver</a>
                                <a class="table-action-link sales-invoice-link" target="_blank" rel="noopener"
                                    href="<?= e(APP_BASE_URL) ?>/admin/workshop/view.php?id=<?= (int) $order['id'] ?>&amp;print=1">Imprimir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php render_sales_layout_end(); ?>
