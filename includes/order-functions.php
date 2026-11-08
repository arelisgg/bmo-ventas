<?php

declare(strict_types=1);

require_once __DIR__ . '/wholesale-functions.php';
require_once __DIR__ . '/promotion-functions.php';
require_once __DIR__ . '/sales-functions.php';
require_once __DIR__ . '/payment-functions.php';


/*
|--------------------------------------------------------------------------
| Catalog Orders (Pedidos)
|--------------------------------------------------------------------------
|
| The catalog cart saves each order here (order-submit.php) before it
| opens WhatsApp, so the message carries its number (P-00012) and the
| shop sees it in Administración > Pedidos. Tables come from
| database/migrations/015_orders.sql; until then the cart still works
| (it only sends WhatsApp) and the admin page shows the import notice.
|
| From an order the shop can:
| - create the sale (admin/sales/create.php?from_order=ID): models in
|   stock become inventory rows, the rest "por encargo" rows;
| - create the workshop order (admin/workshop/create.php?from_order=ID);
| - follow it with the statuses below.
|
| Prices are always recalculated here with the same rules as the
| sale form: wholesale price for a model whose pairs reach its minimum,
| OR the best current promotion that is not first-purchase-only,
| whichever is cheaper, never both. They are an estimate: the sale is
| what counts.
|
*/

function orders_tables_exist(PDO $pdo): bool
{
    static $exists = null;

    if ($exists === null) {
        try {
            $pdo->query('SELECT 1 FROM orders LIMIT 1');
            $pdo->query('SELECT 1 FROM order_items LIMIT 1');
            $exists = true;
        } catch (PDOException) {
            $exists = false;
        }
    }

    return $exists;
}

function order_statuses(): array
{
    return [
        'new' => 'Nuevo',
        'confirmed' => 'Confirmado',
        'sold' => 'Vendido',
        'cancelled' => 'Cancelado',
    ];
}

function order_number(int $orderId): string
{
    return 'P-' . str_pad((string) $orderId, 5, '0', STR_PAD_LEFT);
}

function count_new_orders(PDO $pdo): int
{
    if (!orders_tables_exist($pdo)) {
        return 0;
    }

    return (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'new'")->fetchColumn();
}

function render_orders_migration_notice(): void
{
    ?>
    <section class="empty-state">
        <h2>Falta crear las tablas de pedidos</h2>
        <p>
            En <a href="<?= e(APP_BASE_URL) ?>/admin/settings/">Configuración</a> pulsa
            <strong>ACTUALIZAR BASE DE DATOS</strong> (solo el dueño puede hacerlo). Mientras tanto el carrito del
            catálogo sigue enviando los pedidos por WhatsApp, pero no se guardan aquí.
        </p>
    </section>
    <?php
}


/*
|--------------------------------------------------------------------------
| Pricing (same rules as the cart and the sale form)
|--------------------------------------------------------------------------
|
| $lines: [['product' => row, 'quantity' => int, 'wholesale' => bool], ...]
| Fills unit_price / line_total / priced_wholesale on each line and
| returns [lines, subtotal, discount, total, note, isWholesale].
|
*/

function order_price_lines(PDO $pdo, array $lines): array
{
    $pairsByProduct = [];

    foreach ($lines as $line) {
        $id = (int) $line['product']['id'];
        $pairsByProduct[$id] = ($pairsByProduct[$id] ?? 0) + $line['quantity'];
    }

    $wholesalePrice = static function (array $product): ?float {
        return wholesale_label($product) !== '' ? (float) $product['wholesale_price'] : null;
    };

    $retailUnit = static function (array $product) use ($wholesalePrice): ?float {
        return $product['price'] !== null ? (float) $product['price'] : $wholesalePrice($product);
    };

    $wholesaleOk = static function (array $product) use ($pairsByProduct, $wholesalePrice): bool {
        return $wholesalePrice($product) !== null &&
            $pairsByProduct[(int) $product['id']] >= max(1, (int) ($product['wholesale_min_qty'] ?? 0));
    };

    $retailTotal = 0.0;
    $wholesaleTotal = 0.0;
    $promoLines = [];

    foreach ($lines as $line) {
        $unit = $retailUnit($line['product']);

        if ($unit === null) {
            continue;
        }

        $retailTotal += $unit * $line['quantity'];
        $wholesaleTotal += ($wholesaleOk($line['product']) ? $wholesalePrice($line['product']) : $unit) * $line['quantity'];
        $promoLines[] = [
            'product_id' => (int) $line['product']['id'],
            'category_id' => (int) $line['product']['category_id'],
            'quantity' => $line['quantity'],
            'unit_price' => $unit,
        ];
    }

    $best = null;

    foreach (current_promotions($pdo) as $promotion) {
        if ((int) $promotion['first_purchase_only'] === 1) {
            continue;
        }

        $discount = promotion_discount_for($promotion, $promoLines);

        if ($discount > 0 && ($best === null || $discount > $best['discount'])) {
            $best = ['promotion' => $promotion, 'discount' => $discount];
        }
    }

    $promotionTotal = $best !== null ? $retailTotal - $best['discount'] : $retailTotal;
    $useWholesale = $wholesaleTotal < $retailTotal - 0.004 && $wholesaleTotal <= $promotionTotal;

    foreach ($lines as $index => $line) {
        $unit = $retailUnit($line['product']);
        $pricedWholesale = $useWholesale && $wholesaleOk($line['product']);

        if ($pricedWholesale) {
            $unit = $wholesalePrice($line['product']);
        }

        $lines[$index]['unit_price'] = $unit;
        $lines[$index]['line_total'] = $unit === null ? 0.0 : round($unit * $line['quantity'], 2);
        $lines[$index]['priced_wholesale'] = $pricedWholesale;
    }

    $note = null;
    $discount = 0.0;
    $subtotal = round($retailTotal, 2);

    if ($useWholesale) {
        // Lines already carry the wholesale price.
        $subtotal = round($wholesaleTotal, 2);
        $note = sprintf('Precio mayorista (ahorro %s)', format_money($retailTotal - $wholesaleTotal));
    } elseif ($best !== null) {
        $discount = round($best['discount'], 2);
        $note = sprintf('Promoción "%s"', $best['promotion']['name']);
    }

    return [
        'lines' => $lines,
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => round($subtotal - $discount, 2),
        'note' => $note,
        'promotion_id' => !$useWholesale && $best !== null ? (int) $best['promotion']['id'] : null,
        'is_wholesale' => $useWholesale,
    ];
}


/*
|--------------------------------------------------------------------------
| Create From The Catalog Cart
|--------------------------------------------------------------------------
|
| $payload: decoded JSON from the cart:
| { name, phone, note, lines: [{ id, color, size, quantity, wholesale }] }
| Throws DomainException with a message for the customer.
|
*/

function create_catalog_order(PDO $pdo, array $payload, string $clientIp): array
{
    $text = static fn (mixed $value, int $max): string =>
        is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';

    $requested = is_array($payload['lines'] ?? null) ? array_slice($payload['lines'], 0, 60) : [];

    if ($requested === []) {
        throw new DomainException('El carrito está vacío.');
    }

    // Light spam guard: at most 15 orders per connection per hour.
    $recent = $pdo->prepare(
        'SELECT COUNT(*) FROM orders
         WHERE client_ip = :ip AND created_at >= (NOW() - INTERVAL 1 HOUR)'
    );
    $recent->execute(['ip' => $clientIp]);

    if ((int) $recent->fetchColumn() >= 15) {
        throw new DomainException('Recibimos muchos pedidos seguidos. Escríbenos directamente por WhatsApp.');
    }

    $productIds = array_values(array_unique(array_filter(array_map(
        static fn (mixed $line): int => is_array($line) ? (int) ($line['id'] ?? 0) : 0,
        $requested
    ))));

    if ($productIds === []) {
        throw new DomainException('El carrito está vacío.');
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $statement = $pdo->prepare(
        'SELECT p.id, p.code, p.name, p.price, p.main_image, p.category_id, ' .
        wholesale_select_columns($pdo) . ' 1 AS loaded
         FROM products p
         INNER JOIN categories c ON c.id = p.category_id
         WHERE p.is_active = 1 AND c.is_active = 1 AND p.id IN (' . $placeholders . ')'
    );
    $statement->execute($productIds);

    $products = [];

    foreach ($statement->fetchAll() as $row) {
        $products[(int) $row['id']] = $row + ['is_wholesale' => 0, 'wholesale_price' => null, 'wholesale_min_qty' => null];
    }

    $variantStatement = $pdo->prepare(
        'SELECT id, product_id, color_name, size, stock
         FROM product_variants
         WHERE product_id IN (' . $placeholders . ')'
    );
    $variantStatement->execute($productIds);

    $variants = [];

    foreach ($variantStatement->fetchAll() as $variant) {
        $key = (int) $variant['product_id'] . '|' . mb_strtolower(trim((string) $variant['color_name'])) . '|' . trim((string) $variant['size']);
        $variants[$key] = $variant;
    }

    $lines = [];
    $merged = [];

    foreach ($requested as $line) {
        if (!is_array($line)) {
            continue;
        }

        $product = $products[(int) ($line['id'] ?? 0)] ?? null;
        $color = $text($line['color'] ?? '', 100);
        $size = $text($line['size'] ?? '', 20);
        $quantity = min(500, max(0, (int) ($line['quantity'] ?? 0)));
        $isWholesale = !empty($line['wholesale']) && $product !== null && wholesale_label($product) !== '';

        if ($product === null || $color === '' || $size === '' || $quantity < 1) {
            continue;
        }

        $variant = $variants[(int) $product['id'] . '|' . mb_strtolower($color) . '|' . $size] ?? null;

        if (!$isWholesale) {
            // Retail only from stock.
            if ($variant === null || (int) $variant['stock'] < 1) {
                throw new DomainException(sprintf('%s talla %s ya no está disponible. Quítalo del carrito.', $product['name'], $size));
            }

            $quantity = min($quantity, (int) $variant['stock']);
        }

        $key = $product['id'] . '|' . mb_strtolower($color) . '|' . $size . '|' . ($isWholesale ? 'w' : 'r');

        if (isset($merged[$key])) {
            $lines[$merged[$key]]['quantity'] += $quantity;
            continue;
        }

        $merged[$key] = count($lines);
        $lines[] = [
            'product' => $product,
            'variant_id' => $variant !== null ? (int) $variant['id'] : null,
            'color' => $variant !== null ? trim((string) $variant['color_name']) : $color,
            'size' => $size,
            'quantity' => $quantity,
            'wholesale' => $isWholesale,
        ];
    }

    if ($lines === []) {
        throw new DomainException('El carrito está vacío.');
    }

    $pricing = order_price_lines($pdo, $lines);

    $pdo->beginTransaction();

    try {
        $pdo->prepare(
            'INSERT INTO orders
                (customer_name, customer_phone, customer_note, status, subtotal, discount, total,
                 pricing_note, is_wholesale, client_ip)
             VALUES
                (:name, :phone, :note, "new", :subtotal, :discount, :total, :pricing_note, :is_wholesale, :ip)'
        )->execute([
            'name' => ($name = $text($payload['name'] ?? '', 150)) !== '' ? $name : null,
            'phone' => ($phone = $text($payload['phone'] ?? '', 40)) !== '' ? $phone : null,
            'note' => ($note = $text($payload['note'] ?? '', 1000)) !== '' ? $note : null,
            'subtotal' => $pricing['subtotal'],
            'discount' => $pricing['discount'],
            'total' => $pricing['total'],
            'pricing_note' => $pricing['note'],
            'is_wholesale' => $pricing['is_wholesale'] || in_array(true, array_column($lines, 'wholesale'), true) ? 1 : 0,
            'ip' => $clientIp,
        ]);

        $orderId = (int) $pdo->lastInsertId();

        $insert = $pdo->prepare(
            'INSERT INTO order_items
                (order_id, product_id, variant_id, product_code, product_name, image_path, color_name, size,
                 quantity, unit_price, line_total, is_wholesale)
             VALUES
                (:order_id, :product_id, :variant_id, :code, :name, :image, :color, :size,
                 :quantity, :unit_price, :line_total, :is_wholesale)'
        );

        foreach ($pricing['lines'] as $line) {
            $insert->execute([
                'order_id' => $orderId,
                'product_id' => (int) $line['product']['id'],
                'variant_id' => $line['variant_id'],
                'code' => (string) $line['product']['code'],
                'name' => (string) $line['product']['name'],
                'image' => $line['product']['main_image'] ?: null,
                'color' => $line['color'],
                'size' => $line['size'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
                'is_wholesale' => $line['wholesale'] ? 1 : 0,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return [
        'id' => $orderId,
        'number' => order_number($orderId),
        'total' => $pricing['total'],
    ];
}


/*
|--------------------------------------------------------------------------
| Load
|--------------------------------------------------------------------------
*/

function load_order(PDO $pdo, int $orderId): ?array
{
    $statement = $pdo->prepare(
        'SELECT o.*, s.sale_number, s.status AS sale_status, s.total AS sale_total,
            ' . sale_paid_sql($pdo, 's.id') . ' AS sale_paid
         FROM orders o
         LEFT JOIN sales s ON s.id = o.sale_id
         WHERE o.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $orderId]);
    $order = $statement->fetch();

    if (!$order) {
        return null;
    }

    $items = $pdo->prepare(
        'SELECT oi.*, pv.stock AS current_stock, p.category_id, p.main_image AS current_image
         FROM order_items oi
         LEFT JOIN product_variants pv ON pv.id = oi.variant_id
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = :id
         ORDER BY oi.id ASC'
    );
    $items->execute(['id' => $orderId]);
    $order['items'] = $items->fetchAll();
    $order['pairs'] = array_sum(array_map('intval', array_column($order['items'], 'quantity')));

    return $order;
}

/**
 * Digits for wa.me (adds 505 to an 8-digit Nicaraguan number).
 */
function order_whatsapp_digits(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

    return strlen($digits) === 8 ? '505' . $digits : $digits;
}


/*
|--------------------------------------------------------------------------
| Into The Sale Form / Workshop Form
|--------------------------------------------------------------------------
*/

/**
 * Form + items for admin/sales/create.php. Lines with stock become
 * inventory rows; the rest (or wholesale lines without enough stock)
 * become "por encargo" rows with the model's data.
 */
function order_sale_form(PDO $pdo, array $order): array
{
    $money = static fn (mixed $value): string =>
        $value === null ? '' : number_format((float) $value, 2, '.', '');

    $items = [];

    foreach ($order['items'] as $item) {
        $stock = $item['current_stock'] !== null ? (int) $item['current_stock'] : 0;

        if ($item['variant_id'] !== null && $stock >= (int) $item['quantity']) {
            $items[] = [
                'source' => 'inventory',
                'variant_id' => (string) $item['variant_id'],
                'quantity' => (string) $item['quantity'],
                'unit_price' => $money($item['unit_price']),
            ];

            continue;
        }

        $items[] = [
            'source' => 'workshop',
            'variant_id' => '',
            'quantity' => (string) $item['quantity'],
            'unit_price' => $money($item['unit_price']),
            'custom_name' => (string) $item['product_name'],
            'custom_code' => (string) $item['product_code'],
            'custom_color' => (string) $item['color_name'],
            'custom_size' => (string) $item['size'],
            'custom_category' => (string) ($item['category_id'] ?? ''),
            'custom_visibility' => 'hidden',
            'custom_description' => '',
            'custom_cost' => '',
            'existing_image' => '',
        ];
    }

    $notes = 'Pedido ' . order_number((int) $order['id']) . ' del catálogo.';

    if ((string) $order['customer_note'] !== '') {
        $notes .= ' Nota del cliente: ' . $order['customer_note'];
    }

    return [
        'form' => [
            'customer_name' => (string) ($order['customer_name'] ?? ''),
            'customer_phone' => (string) ($order['customer_phone'] ?? ''),
            'channel' => (int) $order['is_wholesale'] === 1 ? 'wholesale' : 'whatsapp',
            'notes' => $notes,
        ],
        'items' => $items,
    ];
}

/**
 * Form for admin/workshop/create.php: one model per product with its
 * colors and sizes.
 */
function order_workshop_form(array $order): array
{
    $models = [];

    foreach ($order['items'] as $item) {
        $key = $item['product_id'] !== null ? 'p' . $item['product_id'] : 'n' . $item['product_code'];

        if (!isset($models[$key])) {
            $models[$key] = workshop_empty_model();
            $models[$key]['product_id'] = (string) ($item['product_id'] ?? '');
            $models[$key]['name'] = (string) $item['product_name'];
            $models[$key]['code'] = (string) $item['product_code'];
            $models[$key]['image_path'] = '';
            $models[$key]['colors'] = [];
        }

        $colorIndex = null;

        foreach ($models[$key]['colors'] as $index => $color) {
            if (mb_strtolower($color['name']) === mb_strtolower((string) $item['color_name'])) {
                $colorIndex = $index;
            }
        }

        if ($colorIndex === null) {
            $models[$key]['colors'][] = ['name' => (string) $item['color_name'], 'qty' => []];
            $colorIndex = array_key_last($models[$key]['colors']);
        }

        $size = (string) $item['size'];
        $current = (int) ($models[$key]['colors'][$colorIndex]['qty'][$size] ?? 0);
        $models[$key]['colors'][$colorIndex]['qty'][$size] = (string) ($current + (int) $item['quantity']);
    }

    return [
        'reference' => trim((string) ($order['customer_name'] ?? '') . ' · ' . order_number((int) $order['id']), ' ·'),
        'sale_id' => (string) ($order['sale_id'] ?? ''),
        'sale_number' => (string) ($order['sale_number'] ?? ''),
        'due_date' => '',
        'notes' => (string) ($order['customer_note'] ?? ''),
        'models' => $models !== [] ? array_values($models) : [workshop_empty_model()],
    ];
}

/**
 * After a sale or a workshop order is saved from an order.
 */
function link_order_to_sale(PDO $pdo, int $orderId, int $saleId): void
{
    if (orders_tables_exist($pdo)) {
        $pdo->prepare("UPDATE orders SET sale_id = :sale, status = IF(status = 'cancelled', status, 'sold') WHERE id = :id")
            ->execute(['sale' => $saleId, 'id' => $orderId]);
    }
}

function link_order_to_workshop(PDO $pdo, int $orderId, int $workshopOrderId): void
{
    if (orders_tables_exist($pdo)) {
        $pdo->prepare("UPDATE orders SET workshop_order_id = :workshop, status = IF(status = 'new', 'confirmed', status) WHERE id = :id")
            ->execute(['workshop' => $workshopOrderId, 'id' => $orderId]);
    }
}
