<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/order-functions.php';
require_once __DIR__ . '/../sales/_layout.php';

require_admin();

$orderId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($orderId === false || !orders_tables_exist($pdo)) {
    header('Location: ' . APP_BASE_URL . '/admin/orders/');
    exit;
}

$statuses = order_statuses();


/*
|--------------------------------------------------------------------------
| Status / Notes / Delete
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    block_demo_changes();

    if (verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'update' && isset($statuses[(string) ($_POST['status'] ?? '')])) {
            $notes = trim((string) ($_POST['admin_notes'] ?? ''));

            $pdo->prepare('UPDATE orders SET status = :status, admin_notes = :notes WHERE id = :id')
                ->execute([
                    'status' => (string) $_POST['status'],
                    'notes' => $notes !== '' ? mb_substr($notes, 0, 2000) : null,
                    'id' => $orderId,
                ]);

            header('Location: ' . APP_BASE_URL . '/admin/orders/view.php?id=' . $orderId . '&updated=1');
            exit;
        }

        if ($action === 'delete' && admin_is_owner()) {
            $pdo->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $orderId]);
            header('Location: ' . APP_BASE_URL . '/admin/orders/?deleted=1');
            exit;
        }
    }
}

$order = load_order($pdo, $orderId);

if ($order === null) {
    header('Location: ' . APP_BASE_URL . '/admin/orders/');
    exit;
}

$base = APP_BASE_URL;
$number = order_number((int) $order['id']);
$phoneDigits = order_whatsapp_digits($order['customer_phone']);
$canWorkshop = admin_can('workshop');
$missingStock = count(array_filter(
    $order['items'],
    static fn (array $item): bool => $item['current_stock'] === null || (int) $item['current_stock'] < (int) $item['quantity']
));
$imageUrl = static function (array $item) use ($base): string {
    $path = (string) ($item['current_image'] ?: $item['image_path']);

    return $path === '' ? '' : (preg_match('#^https?://#', $path) ? $path : $base . '/' . ltrim($path, '/'));
};

render_sales_layout_start('Pedido ' . $number, 'orders');

?>

<div class="page-header sale-view-header">
    <div>
        <span class="page-eyebrow">PEDIDO DEL CATÁLOGO</span>
        <h1 class="page-title"><?= e($number) ?></h1>
        <p class="page-description">
            <?= e(date('d/m/Y H:i', strtotime((string) $order['created_at']))) ?> ·
            <?= (int) $order['pairs'] ?> <?= (int) $order['pairs'] === 1 ? 'par' : 'pares' ?> ·
            <span class="status-badge order-status-<?= e($order['status']) ?>"><?= e($statuses[$order['status']] ?? $order['status']) ?></span>
        </p>
    </div>
    <div class="sale-actions">
        <a class="admin-button admin-button-outline" href="<?= e($base) ?>/admin/orders/">VOLVER</a>
        <?php if ($phoneDigits !== ''): ?>
            <a class="admin-button admin-button-outline" target="_blank" rel="noopener noreferrer"
                href="https://wa.me/<?= e($phoneDigits) ?>?text=<?= e(rawurlencode('Hola' . ($order['customer_name'] ? ' ' . $order['customer_name'] : '') . ', te escribimos de ' . business_name() . ' por tu pedido ' . $number . '.')) ?>">
                WHATSAPP DEL CLIENTE
            </a>
        <?php endif; ?>
        <?php if ($canWorkshop): ?>
            <?php if ($order['workshop_order_id'] !== null): ?>
                <a class="admin-button admin-button-outline" href="<?= e($base) ?>/admin/workshop/view.php?id=<?= (int) $order['workshop_order_id'] ?>">
                    VER PEDIDO AL TALLER
                </a>
            <?php elseif ($order['status'] !== 'cancelled'): ?>
                <a class="admin-button admin-button-outline" href="<?= e($base) ?>/admin/workshop/create.php?from_order=<?= (int) $order['id'] ?>">
                    ENVIAR AL TALLER
                </a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($order['sale_id'] !== null): ?>
            <a class="admin-button" href="<?= e($base) ?>/admin/sales/view.php?id=<?= (int) $order['sale_id'] ?>">
                VER VENTA <?= e((string) $order['sale_number']) ?>
            </a>
        <?php elseif ($order['status'] !== 'cancelled' && admin_can('sales')): ?>
            <a class="admin-button" href="<?= e($base) ?>/admin/sales/create.php?from_order=<?= (int) $order['id'] ?>">
                CREAR VENTA
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_GET['updated'])): ?>
    <div class="admin-notice admin-notice-success">Pedido actualizado.</div>
<?php endif; ?>

<?php if ($order['sale_id'] === null && $order['status'] !== 'cancelled' && $missingStock > 0): ?>
    <div class="admin-notice">
        <?= $missingStock ?> <?= $missingStock === 1 ? 'línea no tiene' : 'líneas no tienen' ?> stock suficiente hoy.
        Al crear la venta irán como <strong>por encargo</strong><?= $canWorkshop ? ', y puedes mandarlas al taller con «ENVIAR AL TALLER»' : '' ?>.
    </div>
<?php endif; ?>

<div class="sale-detail-grid">

    <section class="dashboard-panel sale-view-products">
        <div class="dashboard-panel-header">
            <div>
                <span class="page-eyebrow">PRODUCTOS</span>
                <h2>Lo que pidió el cliente</h2>
            </div>
            <span class="sale-view-item-count"><?= count($order['items']) ?> <?= count($order['items']) === 1 ? 'línea' : 'líneas' ?></span>
        </div>

        <div class="sale-view-product-list">
            <?php foreach ($order['items'] as $item): ?>
                <?php
                $stock = $item['current_stock'] !== null ? (int) $item['current_stock'] : null;
                $enough = $stock !== null && $stock >= (int) $item['quantity'];
                ?>
                <article class="sale-view-product">
                    <div class="sale-view-product-image">
                        <?php if (($url = $imageUrl($item)) !== ''): ?>
                            <img src="<?= e($url) ?>" alt="<?= e($item['product_name']) ?>">
                        <?php else: ?>
                            <span>Sin imagen</span>
                        <?php endif; ?>
                    </div>
                    <div class="sale-view-product-info">
                        <span class="product-code"><?= e($item['product_code']) ?></span>
                        <strong><?= e($item['product_name']) ?></strong>
                        <div class="sale-view-product-meta">
                            <?php if ((int) $item['is_wholesale'] === 1): ?>
                                <span class="sale-source-badge">Al por mayor</span>
                            <?php endif; ?>
                            <span><?= e($item['color_name']) ?></span>
                            <span>Talla <?= e($item['size']) ?></span>
                        </div>
                        <span class="order-stock <?= $enough ? 'is-ok' : 'is-missing' ?>">
                            <?= $stock === null ? 'Sin inventario de esta talla' : ($enough ? 'En stock: ' . $stock : 'Stock hoy: ' . $stock) ?>
                        </span>
                    </div>
                    <div class="sale-view-product-quantity"><span>CANT.</span><strong><?= (int) $item['quantity'] ?></strong></div>
                    <div class="sale-view-product-price">
                        <span>PRECIO</span>
                        <strong><?= $item['unit_price'] !== null ? e(format_money($item['unit_price'])) : 'A consultar' ?></strong>
                    </div>
                    <div class="sale-view-product-total"><span>TOTAL</span><strong><?= e(format_money($item['line_total'])) ?></strong></div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="sale-view-summary">
        <dl class="sale-totals">
            <div><dt>Subtotal</dt><dd><?= e(format_money($order['subtotal'])) ?></dd></div>
            <?php if ((float) $order['discount'] > 0): ?>
                <div><dt><?= e((string) $order['pricing_note']) ?></dt><dd>−<?= e(format_money($order['discount'])) ?></dd></div>
            <?php elseif ((string) $order['pricing_note'] !== ''): ?>
                <div><dt><?= e((string) $order['pricing_note']) ?></dt><dd>Aplicado</dd></div>
            <?php endif; ?>
            <div class="sale-totals-grand"><dt>Total estimado</dt><dd><?= e(format_money($order['total'])) ?></dd></div>
        </dl>
        </div>
    </section>

    <aside class="dashboard-panel sale-view-sidebar">
        <div class="sale-view-meta-section">
            <span class="sale-view-meta-title">CLIENTE</span>
            <p><strong><?= e($order['customer_name'] ?: 'Sin nombre') ?></strong></p>
            <p><?= e($order['customer_phone'] ?: 'Sin teléfono (llegó por WhatsApp)') ?></p>
            <?php if ((string) $order['customer_note'] !== ''): ?>
                <p class="sale-view-notes"><?= nl2br(e($order['customer_note'])) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($order['sale_id'] !== null): ?>
            <div class="sale-view-meta-section">
                <span class="sale-view-meta-title">VENTA <?= e((string) $order['sale_number']) ?></span>
                <p>Total: <strong><?= e(format_money($order['sale_total'])) ?></strong></p>
                <p>Pagado: <?= e(format_money($order['sale_paid'])) ?></p>
                <?php if ($order['sale_status'] !== 'cancelled' && (float) $order['sale_total'] - (float) $order['sale_paid'] > 0.005): ?>
                    <p class="order-stock is-missing">
                        Saldo pendiente: <?= e(format_money((float) $order['sale_total'] - (float) $order['sale_paid'])) ?>
                    </p>
                <?php elseif ($order['sale_status'] === 'paid'): ?>
                    <p class="order-stock is-ok">Pagada completa</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form class="sale-view-meta-section order-update-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update">
            <span class="sale-view-meta-title">SEGUIMIENTO</span>
            <label class="form-label" for="status">Estado</label>
            <select class="form-select" id="status" name="status">
                <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $order['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="form-label" for="admin_notes">Notas internas</label>
            <textarea class="form-input" id="admin_notes" name="admin_notes" rows="3"
                placeholder="Ej.: confirmado por WhatsApp, entrega el sábado"><?= e((string) $order['admin_notes']) ?></textarea>
            <button class="admin-button" type="submit">GUARDAR</button>
        </form>

        <?php if (admin_is_owner()): ?>
            <form class="sale-view-meta-section" method="post"
                onsubmit="return confirm('¿Eliminar el pedido <?= e($number) ?>? La venta o el pedido al taller que tenga no se borran.');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <button class="admin-button admin-button-outline table-action-danger" type="submit">ELIMINAR PEDIDO</button>
            </form>
        <?php endif; ?>
    </aside>
</div>

<?php render_sales_layout_end(); ?>
