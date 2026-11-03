<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/product-functions.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/wholesale-functions.php';
require_once __DIR__ . '/../../includes/finance-functions.php';
require_once __DIR__ . '/../../includes/payment-functions.php';
require_once __DIR__ . '/../../includes/promotion-functions.php';
require_once __DIR__ . '/_layout.php';

require_admin();

$tablesReady = sales_tables_exist($pdo);

$channels = sale_channels();
$paymentMethods = sale_payment_methods();

$newSaleStatuses = [
    'paid' => sale_statuses()['paid'],
    'pending' => sale_statuses()['pending'],
];

$docTypes = sale_doc_types();


/*
|--------------------------------------------------------------------------
| Edit Mode (?id=)
|--------------------------------------------------------------------------
|
| Saving an edit gives back the stock the sale held, replaces its items
| and takes the stock again when the new version is an invoice.
|
*/

$editId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editData = null;

if ($tablesReady && $editId !== false) {
    $editData = load_sale_for_edit($pdo, $editId);

    if ($editData === null) {
        header('Location: ' . APP_BASE_URL . '/admin/sales/');
        exit;
    }

    $newSaleStatuses = sale_statuses();
}


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/

$error = '';

$form = [
    'doc_type' => 'invoice',
    'customer_name' => '',
    'customer_phone' => '',
    'channel' => 'whatsapp',
    'payment_method' => 'cash',
    'status' => 'paid',
    'discount' => '',
    'promotion_id' => '',
    'notes' => '',
    'sold_at' => date('Y-m-d\TH:i'),
];

$formItems = [];

if ($editData !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $form = $editData['form'];
    $formItems = $editData['items'];
}


/*
|--------------------------------------------------------------------------
| Save Sale
|--------------------------------------------------------------------------
*/

if (
    $tablesReady &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    foreach (array_keys($form) as $field) {
        $value = $_POST[$field] ?? '';

        $form[$field] = is_string($value)
            ? trim($value)
            : '';
    }

    $postedVariants = $_POST['items']['variant_id'] ?? [];
    $postedQuantities = $_POST['items']['quantity'] ?? [];
    $postedPrices = $_POST['items']['unit_price'] ?? [];
    $postedSources = $_POST['items']['source'] ?? [];
    $postedCustom = [
        'custom_name' => $_POST['items']['custom_name'] ?? [],
        'custom_code' => $_POST['items']['custom_code'] ?? [],
        'custom_color' => $_POST['items']['custom_color'] ?? [],
        'custom_size' => $_POST['items']['custom_size'] ?? [],
        'custom_category' => $_POST['items']['custom_category'] ?? [],
        'custom_visibility' => $_POST['items']['custom_visibility'] ?? [],
        'custom_description' => $_POST['items']['custom_description'] ?? [],
        'custom_cost' => $_POST['items']['custom_cost'] ?? [],
        'existing_image' => $_POST['items']['existing_image'] ?? [],
    ];

    if (
        !is_array($postedVariants) ||
        !is_array($postedQuantities) ||
        !is_array($postedPrices)
    ) {
        $postedVariants = [];
    }

    foreach ($postedVariants as $index => $variantId) {
        $formItems[] = [
            'variant_id' => is_scalar($variantId) ? (string) $variantId : '',
            'quantity' => is_scalar($postedQuantities[$index] ?? null) ? (string) $postedQuantities[$index] : '',
            'unit_price' => is_scalar($postedPrices[$index] ?? null) ? (string) $postedPrices[$index] : '',
            'source' => is_array($postedSources) && ($postedSources[$index] ?? '') === 'workshop' ? 'workshop' : 'inventory',
        ];

        foreach ($postedCustom as $field => $values) {
            $value = is_array($values) ? ($values[$index] ?? '') : '';

            $formItems[array_key_last($formItems)][$field] =
                is_scalar($value) ? trim((string) $value) : '';
        }
    }

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido.';
    }

    if ($error === '' && !isset($channels[$form['channel']])) {
        $error = 'Selecciona un canal de venta válido.';
    }

    if ($error === '' && !isset($paymentMethods[$form['payment_method']])) {
        $error = 'Selecciona un método de pago válido.';
    }

    if ($error === '' && !isset($newSaleStatuses[$form['status']])) {
        $error = 'Selecciona un estado válido.';
    }

    if ($error === '' && !isset($docTypes[$form['doc_type']])) {
        $error = 'Elige si es factura de venta, prefactura o cotización.';
    }

    $soldAt = DateTime::createFromFormat('Y-m-d\TH:i', $form['sold_at']);

    if ($error === '' && $soldAt === false) {
        $error = 'La fecha de venta no es válida.';
    }

    $discount = parse_money_input($form['discount']);

    if ($error === '' && $discount === null) {
        $error = 'El descuento no es válido.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Items
    |--------------------------------------------------------------------------
    */

    $requestedItems = [];

    if ($error === '') {
        foreach ($formItems as $itemIndex => $item) {
            $isCustom = $item['source'] === 'workshop';

            if (
                $isCustom
                    ? $item['custom_name'] === ''
                    : $item['variant_id'] === ''
            ) {
                continue;
            }

            if (!$isCustom && !ctype_digit($item['variant_id'])) {
                $error = 'Uno de los productos no es válido.';
                break;
            }

            $quantity = filter_var(
                $item['quantity'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($quantity === false) {
                $error = 'La cantidad debe ser 1 o mayor.';
                break;
            }

            $unitPrice = parse_money_input($item['unit_price']);

            if ($unitPrice === null) {
                $error = 'Uno de los precios no es válido.';
                break;
            }

            if ($isCustom) {
                $requestedItems[] = [
                    'source' => 'workshop',
                    'variant_id' => null,
                    'quantity' => (int) $quantity,
                    'unit_price' => $unitPrice,
                    'custom_name' => mb_substr($item['custom_name'], 0, 180),
                    'custom_code' => mb_substr($item['custom_code'], 0, 100),
                    'custom_color' => mb_substr($item['custom_color'], 0, 100),
                    'custom_size' => mb_substr($item['custom_size'], 0, 20),
                    'custom_category_id' => ctype_digit($item['custom_category']) ? (int) $item['custom_category'] : null,
                    'custom_visibility' => isset(made_to_order_visibilities()[$item['custom_visibility']])
                        ? $item['custom_visibility']
                        : 'hidden',
                    'custom_description' => mb_substr($item['custom_description'], 0, 5000),
                    'custom_cost' => $item['custom_cost'] === '' ? null : parse_money_input($item['custom_cost']),
                    'existing_image' => sale_existing_image_path($item['existing_image']),
                    'image_file' => [
                        'error' => $_FILES['items_image']['error'][$itemIndex] ?? UPLOAD_ERR_NO_FILE,
                        'tmp_name' => $_FILES['items_image']['tmp_name'][$itemIndex] ?? '',
                        'size' => $_FILES['items_image']['size'][$itemIndex] ?? 0,
                    ],
                ];

                continue;
            }

            $requestedItems[] = [
                'source' => 'inventory',
                'variant_id' => (int) $item['variant_id'],
                'quantity' => (int) $quantity,
                'unit_price' => $unitPrice,
            ];
        }
    }

    if ($error === '' && count($requestedItems) === 0) {
        $error = 'Agrega al menos un producto a la venta.';
    }

    /*
    |--------------------------------------------------------------------------
    | Persist
    |--------------------------------------------------------------------------
    */

    $uploadedImages = [];

    if ($error === '') {
        try {
            foreach ($requestedItems as $requestedIndex => $requestedItem) {
                if ($requestedItem['source'] !== 'workshop') {
                    continue;
                }

                $imagePath = upload_sale_item_image($requestedItem['image_file']);

                $requestedItems[$requestedIndex]['image_path'] = $imagePath ?? $requestedItem['existing_image'];

                if ($imagePath !== null) {
                    $uploadedImages[] = $imagePath;
                }
            }

            $pdo->beginTransaction();

            $consumesStock = sale_consumes_stock($form['doc_type'], $form['status']);

            if ($editData !== null) {
                $current = $pdo->prepare('SELECT doc_type, status FROM sales WHERE id = :id FOR UPDATE');
                $current->execute(['id' => $editId]);
                $current = $current->fetch();

                if (!$current) {
                    throw new DomainException('Este documento ya no existe.');
                }

                if (sale_consumes_stock((string) $current['doc_type'], (string) $current['status'])) {
                    restore_sale_stock($pdo, $editId);
                }

                $pdo->prepare('DELETE FROM sale_items WHERE sale_id = :id')->execute(['id' => $editId]);
            }

            $variantStatement = $pdo->prepare(
                'SELECT
                    pv.id,
                    pv.product_id,
                    pv.color_name,
                    pv.size,
                    pv.stock,
                    p.code,
                    p.name,
                    p.category_id
                 FROM product_variants pv
                 INNER JOIN products p
                    ON p.id = pv.product_id
                 WHERE pv.id = :id
                 FOR UPDATE'
            );

            $lineItems = [];
            $requestedByVariant = [];
            $subtotal = 0.0;
            $promotionLines = [];

            foreach ($requestedItems as $item) {
                if ($item['source'] === 'workshop') {
                    $lineTotal = round(
                        $item['quantity'] * $item['unit_price'],
                        2
                    );

                    $subtotal += $lineTotal;

                    $madeToOrderProduct = save_made_to_order_product($pdo, $item);

                    $promotionLines[] = [
                        'product_id' => (int) $madeToOrderProduct['id'],
                        'category_id' => $item['custom_category_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                    ];
                    save_made_to_order_cost($pdo, $madeToOrderProduct['id'], $item['custom_cost']);

                    $lineItems[] = [
                        'source' => 'workshop',
                        'product_id' => $madeToOrderProduct['id'],
                        'variant_id' => null,
                        'product_code' => $madeToOrderProduct['code'],
                        'product_name' => $item['custom_name'],
                        'color_name' => $item['custom_color'],
                        'size' => $item['custom_size'],
                        'image_path' => $item['image_path'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'line_total' => $lineTotal,
                    ];

                    continue;
                }

                $variantId = $item['variant_id'];

                $variantStatement->execute([
                    'id' => $variantId,
                ]);

                $variant = $variantStatement->fetch();

                if (!$variant) {
                    throw new DomainException(
                        'Uno de los productos ya no existe. Recarga la página.'
                    );
                }

                $requestedByVariant[$variantId] =
                    ($requestedByVariant[$variantId] ?? 0) +
                    $item['quantity'];

                if ($consumesStock && $requestedByVariant[$variantId] > (int) $variant['stock']) {
                    throw new DomainException(sprintf(
                        'No hay suficiente stock de %s (%s, talla %s). Disponible: %d.',
                        $variant['name'],
                        $variant['color_name'],
                        $variant['size'],
                        (int) $variant['stock']
                    ));
                }

                $lineTotal = round(
                    $item['quantity'] * $item['unit_price'],
                    2
                );

                $subtotal += $lineTotal;

                $promotionLines[] = [
                    'product_id' => (int) $variant['product_id'],
                    'category_id' => $variant['category_id'] !== null ? (int) $variant['category_id'] : null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ];

                $lineItems[] = [
                    'source' => 'inventory',
                    'product_id' => (int) $variant['product_id'],
                    'variant_id' => $variantId,
                    'product_code' => $variant['code'],
                    'product_name' => $variant['name'],
                    'color_name' => $variant['color_name'],
                    'size' => $variant['size'],
                    'image_path' => null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ];
            }

            $subtotal = round($subtotal, 2);

            // The promotion the user accepted in the form, checked again here.
            $promotion = null;
            $promotionsReady = promotion_tables_exist($pdo);

            if ($promotionsReady && ctype_digit($form['promotion_id'])) {
                $keptPromotionId = $editData !== null && ($editData['sale']['promotion_id'] ?? null) !== null
                    ? (int) $editData['sale']['promotion_id']
                    : null;

                $promotion = resolve_sale_promotion(
                    $pdo,
                    (int) $form['promotion_id'],
                    $promotionLines,
                    $soldAt->format('Y-m-d'),
                    $form['customer_phone'],
                    $form['customer_name'],
                    $editData !== null ? $editId : null,
                    $keptPromotionId
                );
            }

            $manualDiscount = $discount;
            $discount = round($manualDiscount + ($promotion['discount'] ?? 0), 2);

            if ($discount > $subtotal) {
                throw new DomainException(
                    $promotion !== null
                        ? 'El descuento más la promoción no puede ser mayor que el subtotal.'
                        : 'El descuento no puede ser mayor que el subtotal.'
                );
            }

            if ($editData !== null) {
                $saleId = $editId;

                $pdo->prepare(
                    'UPDATE sales
                     SET
                        doc_type = :doc_type,
                        customer_name = :customer_name,
                        customer_phone = :customer_phone,
                        channel = :channel,
                        payment_method = :payment_method,
                        status = :status,
                        subtotal = :subtotal,
                        discount = :discount,
                        total = :total,
                        notes = :notes,
                        sold_at = :sold_at,
                        cancelled_at = IF(:is_cancelled = 1, COALESCE(cancelled_at, NOW()), NULL)
                     WHERE id = :id'
                )->execute([
                    'doc_type' => $form['doc_type'],
                    'customer_name' => $form['customer_name'] !== '' ? $form['customer_name'] : null,
                    'customer_phone' => $form['customer_phone'] !== '' ? $form['customer_phone'] : null,
                    'channel' => $form['channel'],
                    'payment_method' => $form['payment_method'],
                    'status' => $form['status'],
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'total' => round($subtotal - $discount, 2),
                    'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                    'sold_at' => $soldAt->format('Y-m-d H:i:s'),
                    'is_cancelled' => $form['status'] === 'cancelled' ? 1 : 0,
                    'id' => $saleId,
                ]);
            }

            $insertSale = $editData !== null ? null : $pdo->prepare(
                'INSERT INTO sales
                (
                    doc_type,
                    customer_name,
                    customer_phone,
                    channel,
                    payment_method,
                    status,
                    subtotal,
                    discount,
                    total,
                    notes,
                    admin_id,
                    sold_at
                )
                VALUES
                (
                    :doc_type,
                    :customer_name,
                    :customer_phone,
                    :channel,
                    :payment_method,
                    :status,
                    :subtotal,
                    :discount,
                    :total,
                    :notes,
                    :admin_id,
                    :sold_at
                )'
            );

            $insertSale?->execute([
                'doc_type' => $form['doc_type'],
                'customer_name' => $form['customer_name'] !== '' ? $form['customer_name'] : null,
                'customer_phone' => $form['customer_phone'] !== '' ? $form['customer_phone'] : null,
                'channel' => $form['channel'],
                'payment_method' => $form['payment_method'],
                'status' => $form['status'],
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => round($subtotal - $discount, 2),
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                'admin_id' => isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null,
                'sold_at' => $soldAt->format('Y-m-d H:i:s'),
            ]);

            if ($editData === null) {
                $saleId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare(
                'UPDATE sales
                 SET sale_number = :sale_number
                 WHERE id = :id'
            )->execute([
                        'sale_number' => sale_number_for($form['doc_type'], $saleId),
                        'id' => $saleId,
                    ]);

            // sales.discount holds the whole discount; these say how much was the promotion.
            if ($promotionsReady) {
                $pdo->prepare(
                    'UPDATE sales
                     SET
                        promotion_id = :promotion_id,
                        promotion_name = :promotion_name,
                        promotion_discount = :promotion_discount
                     WHERE id = :id'
                )->execute([
                    'promotion_id' => $promotion['id'] ?? null,
                    'promotion_name' => $promotion['name'] ?? null,
                    'promotion_discount' => $promotion['discount'] ?? 0,
                    'id' => $saleId,
                ]);
            }

            $insertItem = $pdo->prepare(
                'INSERT INTO sale_items
                (
                    sale_id,
                    source,
                    product_id,
                    variant_id,
                    product_code,
                    product_name,
                    color_name,
                    size,
                    image_path,
                    quantity,
                    unit_price,
                    line_total
                )
                VALUES
                (
                    :sale_id,
                    :source,
                    :product_id,
                    :variant_id,
                    :product_code,
                    :product_name,
                    :color_name,
                    :size,
                    :image_path,
                    :quantity,
                    :unit_price,
                    :line_total
                )'
            );

            $decrementStock = $pdo->prepare(
                'UPDATE product_variants
                 SET stock = stock - :quantity
                 WHERE id = :id'
            );

            foreach ($lineItems as $lineItem) {
                $insertItem->execute(
                    ['sale_id' => $saleId] + $lineItem
                );

                if ($lineItem['source'] !== 'inventory' || !$consumesStock) {
                    continue;
                }

                $decrementStock->execute([
                    'quantity' => $lineItem['quantity'],
                    'id' => $lineItem['variant_id'],
                ]);
            }

            fill_sale_item_costs($pdo, $saleId);

            // "Pagada" pays what is still owed with the chosen method;
            // abonos and mixed payments are added from the sale view.
            if (payment_tables_exist($pdo)) {
                if ($form['doc_type'] === 'invoice' && $form['status'] === 'paid') {
                    settle_sale_balance($pdo, $saleId, $form['payment_method']);
                }

                sync_sale_payment_status($pdo, $saleId);
            }

            $pdo->commit();

            header(
                'Location: ' .
                APP_BASE_URL .
                '/admin/sales/view.php?id=' .
                $saleId .
                ($editData !== null ? '&updated=1' : '&created=1')
            );

            exit;
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            array_map('delete_sale_item_image', $uploadedImages);

            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            array_map('delete_sale_item_image', $uploadedImages);

            error_log($exception->getMessage());

            $error = 'No se pudo guardar.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Load Variants For The Picker
|--------------------------------------------------------------------------
*/
$variantOptions = [];

// Catalog models and earlier 'por encargo' products, to sell them por
// encargo even without stock, in a picker with photos.
$madeToOrderProducts = $tablesReady ? made_to_order_products($pdo) : [];

$productCategories = $pdo->query(
    'SELECT id, name
     FROM categories
     WHERE is_active = 1
     ORDER BY sort_order ASC, name ASC'
)->fetchAll();

if ($tablesReady) {

    $variantRows = $pdo->query(
        'SELECT
        pv.id,
        pv.color_name,
        pv.size,
        pv.stock,

        p.code,
        p.name,
        p.price,
        p.category_id,
        ' . wholesale_select_columns($pdo) . '
        p.main_image,
        p.is_active

     FROM product_variants pv

     INNER JOIN products p
        ON p.id = pv.product_id

     WHERE
        (pv.stock > 0 AND p.is_active = 1)' .
        ($editData !== null && $editData['returned_stock'] !== []
            ? ' OR pv.id IN (' . implode(',', array_map('intval', array_keys($editData['returned_stock']))) . ')'
            : '') . '

     ORDER BY
        p.sort_order ASC,
        p.name ASC,
        pv.color_name ASC,
        CAST(pv.size AS UNSIGNED) ASC'
    )->fetchAll();


    foreach ($variantRows as $row) {

        $variantOptions[] = [

            'id' =>
                (int) $row['id'],

            'code' =>
                $row['code'],

            'name' =>
                $row['name'],

            'color' =>
                $row['color_name'],

            'size' =>
                $row['size'],

            'image' =>
                $row['main_image'],

            'category_id' =>
                (int) ($row['category_id'] ?? 0),

            'stock' =>
                (int) $row['stock'] + ($editData['returned_stock'][(int) $row['id']] ?? 0),

            'price' =>
                $row['price'] !== null
                ? (float) $row['price']
                : null,

            'inactive' =>
                (int) $row['is_active'] !== 1,

            'wholesale_price' =>
                (int) ($row['is_wholesale'] ?? 0) === 1 && $row['wholesale_price'] !== null
                ? number_format((float) $row['wholesale_price'], 2, '.', '')
                : '',

            'wholesale_min_qty' =>
                (int) ($row['wholesale_min_qty'] ?? 0),
        ];
    }
}

if (count($formItems) === 0) {
    $formItems[] = [
        'variant_id' => '',
        'quantity' => '1',
        'unit_price' => '',
    ];
}

foreach ($formItems as $index => $item) {
    $formItems[$index] += [
        'source' => 'inventory',
        'custom_name' => '',
        'custom_code' => '',
        'custom_color' => '',
        'custom_size' => '',
        'custom_category' => '',
        'custom_visibility' => 'hidden',
        'custom_description' => '',
        'custom_cost' => '',
        'existing_image' => '',
    ];
}

/*
| Promotions offered in the form (active ones, plus the one an edited
| sale already has). Nothing is applied until the user accepts it.
*/
$salePromotions = [];

if ($tablesReady && promotion_tables_exist($pdo)) {
    $keptPromotionId = (int) ($editData['sale']['promotion_id'] ?? 0);

    foreach (load_promotions($pdo) as $promotionRow) {
        if ((int) $promotionRow['is_active'] === 1 || (int) $promotionRow['id'] === $keptPromotionId) {
            $salePromotions[] = promotion_for_js($promotionRow) + [
                'kept' => (int) $promotionRow['id'] === $keptPromotionId,
            ];
        }
    }
}

render_sales_layout_start($editData !== null ? 'Editar ' . ($editData['sale']['sale_number'] ?? '') : 'Nueva venta');
?>
<div class="page-header">
    <div>
        <span class="page-eyebrow">
            VENTAS
        </span>
        <h1 class="page-title">
            <?= $editData !== null ? 'Editar ' . e($editData['sale']['sale_number'] ?? '') : 'Registrar venta' ?>
        </h1>
        <p class="page-description">
            Solo la factura de venta descuenta el stock de cada talla y cuenta en ventas y finanzas.
            La prefactura no toca el inventario.
            Los productos por encargo (del taller directo al cliente) nunca tocan el inventario.
        </p>
    </div>
    <a class="admin-button admin-button-outline" href="<?= e(APP_BASE_URL) ?>/admin/sales/">
        VOLVER A VENTAS
    </a>
</div>

<?php if (!$tablesReady): ?>
    <?php render_sales_migration_notice(); ?>
<?php else: ?>

    <?php if ($error !== ''): ?>
        <div class="admin-notice admin-notice-error"> <?= e($error) ?> </div>
    <?php endif; ?>

    <form class="admin-form" method="post" id="saleForm" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <section class="form-section">
            <div class="form-section-header">
                <h2>Tipo de documento</h2>
            </div>
            <div class="sale-doc-types">
                <?php foreach ($docTypes as $value => $label): ?>
                    <label class="sale-doc-type">
                        <input type="radio" name="doc_type" value="<?= e($value) ?>" <?= $form['doc_type'] === $value ? 'checked' : '' ?>>
                        <span>
                            <strong><?= e($label) ?></strong>
                            <small>
                                <?= $value === 'invoice'
                                    ? 'Descuenta stock y cuenta como venta.'
                                    : 'No descuenta stock ni cuenta como venta.' ?>
                            </small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="form-section">
            <div class="form-section-header">
                <h2>Productos</h2>
            </div>

            <div class="sale-items" id="saleItems">
                <?php foreach ($formItems as $item): ?>
                    <div class="sale-item-row" data-sale-item>

                        <div class="form-group sale-item-product">

                            <div class="sale-item-source">
                                <label class="form-label">
                                    Producto
                                </label>

                                <select class="sale-source-select" name="items[source][]" data-source-select>
                                    <?php foreach (sale_item_sources() as $sourceValue => $sourceLabel): ?>
                                        <option value="<?= e($sourceValue) ?>" <?= $item['source'] === $sourceValue ? 'selected' : '' ?>>
                                            <?= e($sourceLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="sale-custom-fields" data-custom-fields <?= $item['source'] === 'workshop' ? '' : 'hidden' ?>>
                                <?php if (count($madeToOrderProducts) > 0): ?>
                                    <div class="sale-product-picker sale-custom-previous" data-custom-picker>
                                        <select class="sale-native-select" data-custom-input data-custom-previous tabindex="-1" aria-hidden="true">
                                            <option value="">Producto nuevo</option>
                                            <?php foreach ($madeToOrderProducts as $previousProduct): ?>
                                                <?php
                                                $previousImage = !empty($previousProduct['main_image'])
                                                    ? APP_BASE_URL . '/' . ltrim((string) $previousProduct['main_image'], '/')
                                                    : '';
                                                $previousStock = (int) $previousProduct['stock_total'];
                                                ?>
                                                <option value="<?= e($previousProduct['code']) ?>"
                                                    data-name="<?= e($previousProduct['name']) ?>"
                                                    data-category="<?= (int) $previousProduct['category_id'] ?>"
                                                    data-category-name="<?= e($previousProduct['category_name'] ?? '') ?>"
                                                    data-description="<?= e($previousProduct['description'] ?? '') ?>"
                                                    data-cost="<?= e(admin_can_see_costs() ? ($previousProduct['cost_price'] ?? '') : '') ?>"
                                                    data-price="<?= e($previousProduct['price'] ?? '') ?>"
                                                    data-wholesale-price="<?= e(!empty($previousProduct['is_wholesale']) ? ($previousProduct['wholesale_price'] ?? '') : '') ?>"
                                                    data-wholesale-min="<?= (int) (!empty($previousProduct['is_wholesale']) ? ($previousProduct['wholesale_min_qty'] ?? 0) : 0) ?>"
                                                    data-image="<?= e($previousImage) ?>"
                                                    data-stock="<?= $previousStock ?>">
                                                    <?= e($previousProduct['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button class="sale-product-trigger" type="button" data-custom-trigger>
                                            <span class="sale-product-trigger-image" data-custom-picker-image>
                                                <span class="sale-product-no-image">+</span>
                                            </span>
                                            <span class="sale-product-trigger-info">
                                                <strong data-custom-picker-title>Producto nuevo</strong>
                                                <small data-custom-picker-meta>O elige un modelo del catálogo, aunque no tenga stock</small>
                                            </span>
                                            <span class="sale-product-trigger-arrow">↓</span>
                                        </button>

                                        <div class="sale-product-menu" data-custom-menu hidden>
                                            <div class="sale-product-search">
                                                <input type="search" placeholder="Buscar modelo, código o categoría..."
                                                    autocomplete="off" data-custom-search>
                                            </div>

                                            <div class="sale-product-options">
                                                <button class="sale-product-option" type="button" data-custom-option data-value="" data-search="">
                                                    <span class="sale-product-option-image"><span>+</span></span>
                                                    <span class="sale-product-option-info">
                                                        <strong>Producto nuevo</strong>
                                                        <span>Escribe los datos a mano</span>
                                                    </span>
                                                </button>

                                                <?php foreach ($madeToOrderProducts as $previousProduct): ?>
                                                    <?php
                                                    $previousImage = !empty($previousProduct['main_image'])
                                                        ? APP_BASE_URL . '/' . ltrim((string) $previousProduct['main_image'], '/')
                                                        : '';
                                                    $previousStock = (int) $previousProduct['stock_total'];
                                                    $previousSearch = mb_strtolower(implode(' ', [
                                                        $previousProduct['code'],
                                                        $previousProduct['name'],
                                                        $previousProduct['category_name'] ?? '',
                                                    ]));
                                                    ?>
                                                    <button class="sale-product-option" type="button" data-custom-option
                                                        data-value="<?= e($previousProduct['code']) ?>" data-search="<?= e($previousSearch) ?>">
                                                        <span class="sale-product-option-image">
                                                            <?php if ($previousImage !== ''): ?>
                                                                <img src="<?= e($previousImage) ?>" alt="<?= e($previousProduct['name']) ?>" loading="lazy">
                                                            <?php else: ?>
                                                                <span>Sin imagen</span>
                                                            <?php endif; ?>
                                                        </span>

                                                        <span class="sale-product-option-info">
                                                            <strong><?= e($previousProduct['name']) ?></strong>
                                                            <span>
                                                                <?= e($previousProduct['code']) ?>
                                                                <?php if (!empty($previousProduct['category_name'])): ?>
                                                                    · <?= e($previousProduct['category_name']) ?>
                                                                <?php endif; ?>
                                                                <?php if ((int) $previousProduct['is_active'] !== 1): ?>
                                                                    · Oculto
                                                                <?php endif; ?>
                                                            </span>
                                                        </span>

                                                        <span class="sale-product-option-side">
                                                            <?php if ($previousStock > 0): ?>
                                                                <span class="sale-stock-badge">Stock <?= $previousStock ?></span>
                                                            <?php else: ?>
                                                                <span class="sale-stock-badge is-empty">Sin stock</span>
                                                            <?php endif; ?>
                                                            <?php if ($previousProduct['price'] !== null): ?>
                                                                <small><?= e(format_money($previousProduct['price'])) ?></small>
                                                            <?php endif; ?>
                                                        </span>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <input class="form-input sale-custom-name" type="text" name="items[custom_name][]" maxlength="180"
                                    value="<?= e($item['custom_name']) ?>" placeholder="Nombre del producto (obligatorio)" data-custom-input>
                                <input class="form-input" type="text" name="items[custom_code][]" maxlength="100"
                                    value="<?= e($item['custom_code']) ?>" placeholder="Código (opcional)" data-custom-input>
                                <input class="form-input" type="text" name="items[custom_color][]" maxlength="100"
                                    value="<?= e($item['custom_color']) ?>" placeholder="Color (opcional)" data-custom-input>
                                <input class="form-input" type="text" name="items[custom_size][]" maxlength="20"
                                    value="<?= e($item['custom_size']) ?>" placeholder="Talla (opcional)" data-custom-input>
                                <select class="form-select sale-custom-category" name="items[custom_category][]" data-custom-input>
                                    <option value="">Categoría</option>
                                    <?php foreach ($productCategories as $productCategory): ?>
                                        <option value="<?= (int) $productCategory['id'] ?>" <?= $item['custom_category'] === (string) $productCategory['id'] ? 'selected' : '' ?>>
                                            <?= e($productCategory['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="form-select sale-custom-visibility" name="items[custom_visibility][]" data-custom-visibility>
                                    <?php foreach (made_to_order_visibilities() as $visibilityValue => $visibilityLabel): ?>
                                        <option value="<?= e($visibilityValue) ?>" <?= $item['custom_visibility'] === $visibilityValue ? 'selected' : '' ?>>
                                            <?= e($visibilityLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (finance_tables_exist($pdo) && admin_can_see_costs()): ?>
                                    <label class="sale-custom-cost">
                                        <span>Costo por par (<?= e(currency_symbol()) ?>)</span>
                                        <input class="form-input" type="number" name="items[custom_cost][]" min="0" step="0.01"
                                            value="<?= e($item['custom_cost'] ?? '') ?>" placeholder="Opcional" data-custom-input>
                                    </label>
                                <?php endif; ?>
                                <textarea class="form-input sale-custom-description" name="items[custom_description][]" rows="2"
                                    maxlength="5000" placeholder="Descripción del producto (opcional)" data-custom-input><?= e($item['custom_description']) ?></textarea>
                                <label class="sale-custom-photo">
                                    <input type="file" name="items_image[]" accept="image/jpeg,image/png,image/webp"
                                        data-custom-input data-custom-photo>
                                    <span class="sale-custom-photo-preview" data-custom-photo-preview></span>
                                    <input type="hidden" name="items[existing_image][]" value="<?= e($item['existing_image']) ?>"
                                        data-custom-input data-existing-image
                                        data-url="<?= $item['existing_image'] !== '' ? e(APP_BASE_URL . '/' . ltrim($item['existing_image'], '/')) : '' ?>">
                                    <span class="sale-custom-photo-text" data-custom-photo-text>
                                        + Foto de referencia para el taller (opcional)
                                    </span>
                                </label>
                                <small class="form-hint">
                                    No descuenta stock. Úsalo para encargos y ventas mayoristas que salen del taller.
                                </small>
                            </div>


                            <div class="sale-product-picker" data-product-picker <?= $item['source'] === 'workshop' ? 'hidden' : '' ?>>

                                <!-- REAL SELECT USED BY THE FORM -->

                                <select class="sale-native-select" name="items[variant_id][]" data-variant-select tabindex="-1"
                                    aria-hidden="true">

                                    <option value="">
                                        Selecciona...
                                    </option>


                                    <?php foreach (
                                        $variantOptions
                                        as
                                        $option
                                    ): ?>


                                        <?php

                                        $imageUrl =
                                            !empty(
                                            $option['image']
                                        )
                                            ? APP_BASE_URL .
                                            '/' .
                                            ltrim(
                                                (string) 
                                                $option['image'],
                                                '/'
                                            )
                                            : '';

                                        ?>


                                        <option value="<?= (int) 
                                            $option['id']
                                            ?>" data-stock="<?= (int) 
                                            $option['stock']
                                            ?>" data-price="<?= $option['price'] !== null
                                            ? e(
                                                number_format(
                                                    $option['price'],
                                                    2,
                                                    '.',
                                                    ''
                                                )
                                            )
                                            : ''
                                            ?>" data-wholesale-price="<?= e($option['wholesale_price']) ?>" data-wholesale-min="<?= (int) $option['wholesale_min_qty'] ?>" data-category="<?= (int) $option['category_id'] ?>" data-image="<?= e(
                                            $imageUrl
                                        ) ?>" data-code="<?= e(
                                             $option['code']
                                         ) ?>" data-name="<?= e(
                                              $option['name']
                                          ) ?>" data-color="<?= e(
                                               $option['color']
                                           ) ?>" data-size="<?= e(
                                                $option['size']
                                            ) ?>" <?= $option['stock'] <= 0
                                                 ? 'disabled'
                                                 : ''
                                                 ?>             <?= (string) 
                                                              $option['id']
                                                              ===
                                                              $item['variant_id']
                                                              ? 'selected'
                                                              : ''
                                                              ?>>

                                            <?= e(
                                                $option['name']
                                            ) ?>

                                        </option>


                                    <?php endforeach; ?>

                                </select>

                                <button class="sale-product-trigger" type="button" data-picker-trigger>

                                    <span class="sale-product-trigger-image" data-picker-image>

                                        <span class="sale-product-no-image">
                                            +
                                        </span>

                                    </span>


                                    <span class="sale-product-trigger-info">

                                        <strong data-picker-title>
                                            Selecciona un producto
                                        </strong>

                                        <small data-picker-meta>
                                            Busca por nombre, código, color o talla
                                        </small>

                                    </span>


                                    <span class="sale-product-trigger-arrow">
                                        ↓
                                    </span>

                                </button>
                                <div class="sale-product-menu" data-picker-menu hidden>


                                    <!-- Search -->

                                    <div class="sale-product-search">

                                        <input type="search" placeholder="Buscar producto, código, color o talla..."
                                            autocomplete="off" data-picker-search>

                                    </div>

                                    <!-- Products -->

                                    <div class="sale-product-options">


                                        <?php foreach (
                                            $variantOptions
                                            as
                                            $option
                                        ): ?>


                                            <?php

                                            $imageUrl =
                                                !empty(
                                                $option['image']
                                            )
                                                ? APP_BASE_URL .
                                                '/' .
                                                ltrim(
                                                    (string) 
                                                    $option['image'],
                                                    '/'
                                                )
                                                : '';


                                            $searchText =
                                                strtolower(
                                                    implode(
                                                        ' ',
                                                        [
                                                            $option['code'],
                                                            $option['name'],
                                                            $option['color'],
                                                            $option['size'],
                                                        ]
                                                    )
                                                );

                                            ?>


                                            <button class="
                                sale-product-option
                                <?= $option['stock'] <= 0
                                    ? 'is-out-of-stock'
                                    : ''
                                    ?>
                            " type="button" data-picker-option data-value="<?= (int) 
                                $option['id']
                                ?>" data-search="<?= e(
                                $searchText
                            ) ?>" <?= $option['stock'] <= 0
                                 ? 'disabled'
                                 : ''
                                 ?>>


                                                <!-- Image -->

                                                <span class="sale-product-option-image">

                                                    <?php if (
                                                        $imageUrl !== ''
                                                    ): ?>

                                                        <img src="<?= e(
                                                            $imageUrl
                                                        ) ?>" alt="<?= e(
                                                             $option['name']
                                                         ) ?>">

                                                    <?php else: ?>

                                                        <span>
                                                            Sin imagen
                                                        </span>

                                                    <?php endif; ?>

                                                </span>



                                                <!-- Product -->

                                                <span class="sale-product-option-info">

                                                    <strong>
                                                        <?= e(
                                                            $option['name']
                                                        ) ?>
                                                    </strong>


                                                    <span>

                                                        <?= e(
                                                            $option['code']
                                                        ) ?>

                                                        ·

                                                        <?= e(
                                                            $option['color']
                                                        ) ?>

                                                        · Talla

                                                        <?= e(
                                                            $option['size']
                                                        ) ?>

                                                    </span>

                                                </span>



                                                <!-- Stock / Price -->

                                                <span class="sale-product-option-side">

                                                    <?php if (
                                                        $option['stock'] > 0
                                                    ): ?>

                                                        <span class="sale-stock-badge">

                                                            Stock
                                                            <?= (int) 
                                                                $option['stock']
                                                                ?>

                                                        </span>

                                                    <?php else: ?>

                                                        <span class="
                                            sale-stock-badge
                                            is-empty
                                        ">
                                                            Agotado
                                                        </span>

                                                    <?php endif; ?>


                                                    <?php if (
                                                        $option['price'] !== null
                                                    ): ?>

                                                        <small>

                                                            <?= e(
                                                                format_money(
                                                                    $option['price']
                                                                )
                                                            ) ?>

                                                        </small>

                                                    <?php endif; ?>

                                                </span>


                                            </button>


                                        <?php endforeach; ?>


                                    </div>

                                </div>

                            </div>

                        </div>

                        <div class="form-group">

                            <label class="form-label">
                                Cantidad
                            </label>

                            <input class="form-input" type="number" min="1" step="1" name="items[quantity][]" value="<?= e(
                                $item['quantity']
                            ) ?>" data-quantity-input>

                        </div>
                        <div class="form-group">

                            <label class="form-label">
                                Precio unitario
                            </label>

                            <input class="form-input" type="text" inputmode="decimal" name="items[unit_price][]" value="<?= e(
                                $item['unit_price']
                            ) ?>" data-price-input>

                        </div>

                        <div class="sale-item-total">

                            <span class="form-label">
                                Total
                            </span>

                            <strong data-line-total>
                                <?= e(currency_symbol()) ?>0.00
                            </strong>

                        </div>

                        <button class="remove-color-button" type="button" data-remove-item aria-label="Quitar producto">
                            ×
                        </button>

                    </div>
                <?php endforeach; ?>
            </div>

            <div class="add-color-wrapper">
                <button class="admin-button admin-button-outline" type="button" id="addSaleItem">
                    + AGREGAR OTRO PRODUCTO
                </button>
            </div>
        </section>

        <section class="form-section">
            <div class="form-section-header">
                <h2>Cliente y pago</h2>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="customer_name">Cliente</label>
                    <input class="form-input" id="customer_name" name="customer_name" maxlength="150"
                        value="<?= e($form['customer_name']) ?>" placeholder="Opcional">
                </div>
                <div class="form-group">
                    <label class="form-label" for="customer_phone">Teléfono</label>
                    <input class="form-input" id="customer_phone" name="customer_phone" maxlength="40"
                        value="<?= e($form['customer_phone']) ?>" placeholder="Opcional">
                </div>
                <div class="form-group">
                    <label class="form-label" for="channel">Canal</label>
                    <select class="form-select" id="channel" name="channel">
                        <?php foreach ($channels as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $form['channel'] === $value ? 'selected' : '' ?>><?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="payment_method">Método de pago</label>
                    <select class="form-select" id="payment_method" name="payment_method">
                        <?php foreach ($paymentMethods as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $form['payment_method'] === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="status">Estado</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach ($newSaleStatuses as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $form['status'] === $value ? 'selected' : '' ?>><?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="sold_at">Fecha</label>
                    <input class="form-input" type="datetime-local" id="sold_at" name="sold_at"
                        value="<?= e($form['sold_at']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="discount">Descuento (<?= e(currency_symbol()) ?>)</label>
                    <input class="form-input" type="text" inputmode="decimal" id="discount" name="discount"
                        value="<?= e($form['discount']) ?>" placeholder="0.00">
                </div>
                <div class="form-group form-group-full">
                    <label class="form-label" for="notes">Notas</label>
                    <textarea class="form-textarea" id="notes" name="notes" rows="3"
                        placeholder="Dirección de entrega, referencia de pago…"><?= e($form['notes']) ?></textarea>
                </div>
            </div>
        </section>

        <section class="form-section sale-offers" id="saleOffers" hidden>
            <div class="form-section-header">
                <h2>Promociones y precio mayorista</h2>
            </div>

            <input type="hidden" name="promotion_id" id="promotionId" value="<?= e($form['promotion_id']) ?>">

            <div class="sale-offers-applied" data-offers-applied></div>
            <div class="sale-offers-list" data-offers-list></div>
            <ul class="sale-offers-notes" data-offers-notes></ul>

            <p class="form-hint">
                Nada se aplica solo: elige una opción y confirma el nuevo precio.
                Una venta lleva una sola promoción y no se junta con el precio mayorista.
            </p>
        </section>

        <div class="inventory-save-bar">
            <div>
                <span class="inventory-save-label">Total de la venta</span>
                <strong id="saleGrandTotal"><?= e(currency_symbol()) ?>0.00</strong>
                <small class="sale-save-promotion" id="salePromotionLine" hidden></small>
            </div>
            <button class="admin-button" type="submit">
                <?= $editData !== null ? 'GUARDAR CAMBIOS' : 'GUARDAR' ?>
            </button>
        </div>
    </form>

        <dialog class="sale-offer-dialog" id="saleOfferDialog">
            <form method="dialog">
                <span class="page-eyebrow" data-dialog-eyebrow>¿APLICAR PROMOCIÓN?</span>
                <h2 data-dialog-title></h2>
                <p data-dialog-detail></p>
                <dl class="sale-offer-dialog-prices">
                    <div>
                        <dt>Total actual</dt>
                        <dd data-dialog-before></dd>
                    </div>
                    <div>
                        <dt>Nuevo total</dt>
                        <dd data-dialog-after></dd>
                    </div>
                    <div>
                        <dt>El cliente ahorra</dt>
                        <dd data-dialog-saving></dd>
                    </div>
                </dl>
                <p class="form-hint" data-dialog-warning hidden></p>
                <div class="sale-offer-dialog-actions">
                    <button class="admin-button admin-button-outline" type="submit" value="reject">NO APLICAR</button>
                    <button class="admin-button" type="submit" value="accept">SÍ, APLICAR</button>
                </div>
            </form>
        </dialog>

    <script>

        document.addEventListener(
            "DOMContentLoaded",
            () => {

                const container =
                    document.getElementById(
                        "saleItems"
                    );

                const addButton =
                    document.getElementById(
                        "addSaleItem"
                    );

                const discountInput =
                    document.getElementById(
                        "discount"
                    );

                const grandTotal =
                    document.getElementById(
                        "saleGrandTotal"
                    );


                /*
                |--------------------------------------------------------------------------
                | Money
                |--------------------------------------------------------------------------
                */

                const money =
                    value =>
                        <?= json_encode(currency_symbol(), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?> +
                        value.toLocaleString(
                            "en-US",
                            {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            }
                        );


                const parse =
                    value => {

                        const number =
                            Number.parseFloat(
                                String(
                                    value
                                ).replace(
                                    /[^0-9.]/g,
                                    ""
                                )
                            );


                        return Number.isFinite(
                            number
                        )
                            ? number
                            : 0;

                    };


                /*
                |--------------------------------------------------------------------------
                | Totals
                |--------------------------------------------------------------------------
                */

                function recalculate() {

                    let subtotal = 0;


                    container
                        .querySelectorAll(
                            "[data-sale-item]"
                        )
                        .forEach(
                            row => {

                                const quantity =
                                    parse(
                                        row.querySelector(
                                            "[data-quantity-input]"
                                        ).value
                                    );


                                const price =
                                    parse(
                                        row.querySelector(
                                            "[data-price-input]"
                                        ).value
                                    );


                                const lineTotal =
                                    quantity *
                                    price;


                                row.querySelector(
                                    "[data-line-total]"
                                ).textContent =
                                    money(
                                        lineTotal
                                    );


                                subtotal +=
                                    lineTotal;

                            }
                        );


                    grandTotal.textContent =
                        money(
                            Math.max(
                                subtotal -
                                parse(
                                    discountInput.value
                                ),
                                0
                            )
                        );

                }


                /*
                |--------------------------------------------------------------------------
                | Sync Product
                |--------------------------------------------------------------------------
                */

                function syncRow(
                    row
                ) {

                    const select =
                        row.querySelector(
                            "[data-variant-select]"
                        );


                    const quantityInput =
                        row.querySelector(
                            "[data-quantity-input]"
                        );


                    const priceInput =
                        row.querySelector(
                            "[data-price-input]"
                        );


                    const option =
                        select.selectedOptions[0];


                    if (
                        option &&
                        option.value !== ""
                    ) {

                        quantityInput.max =
                            option.dataset.stock ||
                            "";


                        if (
                            priceInput
                                .value
                                .trim()
                            === "" &&
                            option.dataset.price
                        ) {

                            priceInput.value =
                                option.dataset.price;

                        }

                    } else {

                        quantityInput.removeAttribute(
                            "max"
                        );

                    }

                }


                /*
                |--------------------------------------------------------------------------
                | Visual Product
                |--------------------------------------------------------------------------
                */

                function renderPickerSelection(
                    row
                ) {

                    const select =
                        row.querySelector(
                            "[data-variant-select]"
                        );


                    const imageContainer =
                        row.querySelector(
                            "[data-picker-image]"
                        );


                    const title =
                        row.querySelector(
                            "[data-picker-title]"
                        );


                    const meta =
                        row.querySelector(
                            "[data-picker-meta]"
                        );


                    const option =
                        select.selectedOptions[0];


                    if (
                        !option ||
                        option.value === ""
                    ) {

                        title.textContent =
                            "Selecciona un producto";


                        meta.textContent =
                            "Busca por nombre, código, color o talla";


                        imageContainer.innerHTML =
                            '<span class="sale-product-no-image">+</span>';


                        return;

                    }


                    title.textContent =
                        option.dataset.name ||
                        "Producto";


                    meta.textContent =
                        [
                            option.dataset.code,

                            option.dataset.color,

                            option.dataset.size
                                ? `Talla ${option.dataset.size}`
                                : null,

                            option.dataset.stock
                                ? `Stock ${option.dataset.stock}`
                                : null
                        ]
                            .filter(Boolean)
                            .join(" · ");


                    imageContainer.innerHTML =
                        "";


                    if (
                        option.dataset.image
                    ) {

                        const image =
                            document.createElement(
                                "img"
                            );


                        image.src =
                            option.dataset.image;


                        image.alt =
                            option.dataset.name ||
                            "";


                        imageContainer.appendChild(
                            image
                        );

                    } else {

                        imageContainer.innerHTML =
                            '<span class="sale-product-no-image">+</span>';

                    }

                }


                /*
                |--------------------------------------------------------------------------
                | Close Pickers
                |--------------------------------------------------------------------------
                */

                function closeProductPickers(
                    except = null
                ) {

                    document
                        .querySelectorAll(
                            "[data-product-picker]"
                        )
                        .forEach(
                            picker => {

                                if (
                                    picker === except
                                ) {
                                    return;
                                }


                                picker.classList.remove(
                                    "is-open"
                                );


                                const menu =
                                    picker.querySelector(
                                        "[data-picker-menu]"
                                    );


                                if (menu) {
                                    menu.hidden = true;
                                }

                            }
                        );

                }


                /*
                |--------------------------------------------------------------------------
                | Product Picker Click
                |--------------------------------------------------------------------------
                */

                container.addEventListener(
                    "click",
                    event => {

                        /*
                        |--------------------------------------------------------------------------
                        | Open
                        |--------------------------------------------------------------------------
                        */

                        const trigger =
                            event.target.closest(
                                "[data-picker-trigger]"
                            );


                        if (trigger) {

                            const picker =
                                trigger.closest(
                                    "[data-product-picker]"
                                );


                            const menu =
                                picker.querySelector(
                                    "[data-picker-menu]"
                                );


                            const opening =
                                menu.hidden;


                            closeProductPickers(
                                picker
                            );


                            menu.hidden =
                                !opening;


                            picker.classList.toggle(
                                "is-open",
                                opening
                            );


                            if (opening) {

                                const search =
                                    picker.querySelector(
                                        "[data-picker-search]"
                                    );


                                requestAnimationFrame(
                                    () => {

                                        search?.focus();

                                    }
                                );

                            }


                            return;

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Select Product
                        |--------------------------------------------------------------------------
                        */

                        const productOption =
                            event.target.closest(
                                "[data-picker-option]"
                            );


                        if (
                            productOption &&
                            !productOption.disabled
                        ) {

                            const row =
                                productOption.closest(
                                    "[data-sale-item]"
                                );


                            const select =
                                row.querySelector(
                                    "[data-variant-select]"
                                );


                            select.value =
                                productOption
                                    .dataset
                                    .value;


                            select.dispatchEvent(
                                new Event(
                                    "change",
                                    {
                                        bubbles: true
                                    }
                                )
                            );


                            renderPickerSelection(
                                row
                            );


                            closeProductPickers();


                            return;

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Remove Product
                        |--------------------------------------------------------------------------
                        */

                        const removeButton =
                            event.target.closest(
                                "[data-remove-item]"
                            );


                        if (!removeButton) {
                            return;
                        }


                        const rows =
                            container.querySelectorAll(
                                "[data-sale-item]"
                            );


                        const row =
                            removeButton.closest(
                                "[data-sale-item]"
                            );


                        if (
                            rows.length > 1
                        ) {

                            row.remove();

                        } else {

                            row.querySelector(
                                "[data-variant-select]"
                            ).value = "";


                            row.querySelector(
                                "[data-quantity-input]"
                            ).value = "1";


                            row.querySelector(
                                "[data-price-input]"
                            ).value = "";


                            renderPickerSelection(
                                row
                            );

                        }


                        recalculate();

                    }
                );


                /*
                |--------------------------------------------------------------------------
                | Product Search
                |--------------------------------------------------------------------------
                */

                container.addEventListener(
                    "input",
                    event => {

                        /*
                        |--------------------------------------------------------------------------
                        | Picker Search
                        |--------------------------------------------------------------------------
                        */

                        if (
                            event.target.matches(
                                "[data-picker-search]"
                            )
                        ) {

                            const picker =
                                event.target.closest(
                                    "[data-product-picker]"
                                );


                            const query =
                                event.target
                                    .value
                                    .trim()
                                    .toLowerCase();


                            picker
                                .querySelectorAll(
                                    "[data-picker-option]"
                                )
                                .forEach(
                                    option => {

                                        const search =
                                            option.dataset.search ||
                                            "";


                                        option.hidden =
                                            query !== "" &&
                                            !search.includes(
                                                query
                                            );

                                    }
                                );


                            return;

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Recalculate Sale
                        |--------------------------------------------------------------------------
                        */

                        recalculate();

                    }
                );


                /*
                |--------------------------------------------------------------------------
                | Variant Change
                |--------------------------------------------------------------------------
                */

                container.addEventListener(
                    "change",
                    event => {

                        const row =
                            event.target.closest(
                                "[data-sale-item]"
                            );


                        if (
                            row &&
                            event.target.matches(
                                "[data-variant-select]"
                            )
                        ) {

                            row.querySelector(
                                "[data-price-input]"
                            ).value = "";


                            syncRow(
                                row
                            );


                            renderPickerSelection(
                                row
                            );

                        }


                        recalculate();

                    }
                );


                /*
                |--------------------------------------------------------------------------
                | Discount
                |--------------------------------------------------------------------------
                */

                discountInput.addEventListener(
                    "input",
                    recalculate
                );


                /*
                |--------------------------------------------------------------------------
                | Add Product
                |--------------------------------------------------------------------------
                */

                addButton.addEventListener(
                    "click",
                    () => {

                        const template =
                            container.querySelector(
                                "[data-sale-item]"
                            );


                        const row =
                            template.cloneNode(
                                true
                            );


                        const select =
                            row.querySelector(
                                "[data-variant-select]"
                            );


                        const quantity =
                            row.querySelector(
                                "[data-quantity-input]"
                            );


                        const price =
                            row.querySelector(
                                "[data-price-input]"
                            );


                        const search =
                            row.querySelector(
                                "[data-picker-search]"
                            );


                        const menu =
                            row.querySelector(
                                "[data-picker-menu]"
                            );


                        select.value =
                            "";


                        quantity.value =
                            "1";


                        quantity.removeAttribute(
                            "max"
                        );


                        price.value =
                            "";


                        if (search) {
                            search.value = "";
                        }


                        if (menu) {
                            menu.hidden = true;
                        }


                        row.classList.remove(
                            "is-open"
                        );


                        row
                            .querySelectorAll(
                                "[data-picker-option]"
                            )
                            .forEach(
                                option => {

                                    option.hidden =
                                        false;

                                }
                            );


                        container.appendChild(
                            row
                        );


                        renderPickerSelection(
                            row
                        );


                        recalculate();

                    }
                );


                /*
                |--------------------------------------------------------------------------
                | Close When Clicking Outside
                |--------------------------------------------------------------------------
                */

                document.addEventListener(
                    "click",
                    event => {

                        if (
                            !event.target.closest(
                                "[data-product-picker]"
                            )
                        ) {

                            closeProductPickers();

                        }

                    }
                );


                /*
                |--------------------------------------------------------------------------
                | Escape
                |--------------------------------------------------------------------------
                */

                document.addEventListener(
                    "keydown",
                    event => {

                        if (
                            event.key ===
                            "Escape"
                        ) {

                            closeProductPickers();

                        }

                    }
                );


                /*
                |--------------------------------------------------------------------------
                | Initial State
                |--------------------------------------------------------------------------
                */

                container
                    .querySelectorAll(
                        "[data-sale-item]"
                    )
                    .forEach(
                        row => {

                            syncRow(
                                row
                            );


                            renderPickerSelection(
                                row
                            );

                        }
                    );


                recalculate();

            }
        );

    </script>

    <script>
        /*
        |--------------------------------------------------------------------------
        | Inventory vs. custom (workshop) items
        |--------------------------------------------------------------------------
        */

        document.addEventListener("DOMContentLoaded", () => {
            const container = document.getElementById("saleItems");
            const addButton = document.getElementById("addSaleItem");

            function applySource(row) {
                const isCustom = row.querySelector("[data-source-select]").value === "workshop";
                const quantityInput = row.querySelector("[data-quantity-input]");
                const option = row.querySelector("[data-variant-select]").selectedOptions[0];

                row.querySelector("[data-custom-fields]").hidden = !isCustom;
                row.querySelector("[data-product-picker]").hidden = isCustom;

                if (!isCustom && option && option.value !== "" && option.dataset.stock) {
                    quantityInput.max = option.dataset.stock;
                } else {
                    quantityInput.removeAttribute("max");
                }
            }

            function renderPhoto(row) {
                const input = row.querySelector("[data-custom-photo]");
                const preview = row.querySelector("[data-custom-photo-preview]");
                const text = row.querySelector("[data-custom-photo-text]");
                const file = input.files && input.files[0];
                const existing = row.querySelector("[data-existing-image]");

                preview.innerHTML = "";

                if (!file && existing && existing.value !== "" && existing.dataset.url) {
                    const current = document.createElement("img");
                    current.src = existing.dataset.url;
                    current.alt = "";
                    preview.appendChild(current);
                    text.textContent = "Foto actual · cambiar foto";
                    return;
                }

                if (!file) {
                    text.textContent = "+ Foto de referencia para el taller (opcional)";
                    return;
                }

                const image = document.createElement("img");
                image.src = URL.createObjectURL(file);
                image.alt = "";
                preview.appendChild(image);
                text.textContent = file.name + " · cambiar foto";
            }

            // Wholesale prices are offered (and confirmed) in the "Promociones y
            // precio mayorista" block below, never applied without asking.
            const channelSelect = document.getElementById("channel");

            // A new product starts at its retail price; any wholesale price applied
            // to the row before is forgotten.
            function applyChannelPrice(row) {
                delete row.dataset.wholesaleApplied;
                delete row.dataset.retailPrice;
            }

            function fillPrevious(row, select) {
                const option = select.selectedOptions[0];

                if (!option || option.value === "") {
                    // "Producto nuevo": clear what a catalog model filled in.
                    ["items[custom_name][]", "items[custom_code][]", "items[custom_description][]", "items[custom_cost][]"]
                        .forEach(name => {
                            const input = row.querySelector(`[name="${name}"]`);

                            if (input) {
                                input.value = "";
                            }
                        });

                    return;
                }

                delete row.dataset.wholesaleApplied;
                delete row.dataset.retailPrice;

                const fields = {
                    "items[custom_name][]": option.dataset.name,
                    "items[custom_code][]": option.value,
                    "items[unit_price][]": option.dataset.price,
                    "items[custom_category][]": option.dataset.category,
                    "items[custom_description][]": option.dataset.description,
                    "items[custom_cost][]": option.dataset.cost,
                };

                Object.entries(fields).forEach(([name, value]) => {
                    const input = row.querySelector(`[name="${name}"]`);

                    if (input && value) {
                        input.value = value;
                        input.dispatchEvent(new Event("input", { bubbles: true }));
                    }
                });
            }

            // Catalog picker for 'por encargo' rows, same look as the inventory picker.
            function renderCustomPicker(row) {
                const picker = row.querySelector("[data-custom-picker]");

                if (!picker) {
                    return;
                }

                const option = picker.querySelector("[data-custom-previous]").selectedOptions[0];
                const imageBox = picker.querySelector("[data-custom-picker-image]");
                const title = picker.querySelector("[data-custom-picker-title]");
                const meta = picker.querySelector("[data-custom-picker-meta]");

                imageBox.innerHTML = '<span class="sale-product-no-image">+</span>';

                if (!option || option.value === "") {
                    title.textContent = "Producto nuevo";
                    meta.textContent = "O elige un modelo del catálogo, aunque no tenga stock";
                    return;
                }

                const stock = Number(option.dataset.stock || 0);

                title.textContent = option.dataset.name || "Producto";
                meta.textContent = [
                    option.value,
                    option.dataset.categoryName,
                    stock > 0 ? `Stock ${stock}` : "Sin stock",
                ].filter(Boolean).join(" · ");

                if (option.dataset.image) {
                    const image = document.createElement("img");
                    image.src = option.dataset.image;
                    image.alt = option.dataset.name || "";
                    imageBox.innerHTML = "";
                    imageBox.appendChild(image);
                }
            }

            function closeCustomPickers(except = null) {
                container.querySelectorAll("[data-custom-picker]").forEach(picker => {
                    if (picker === except) {
                        return;
                    }

                    picker.classList.remove("is-open");
                    picker.querySelector("[data-custom-menu]").hidden = true;
                });
            }

            container.addEventListener("click", event => {
                const trigger = event.target.closest("[data-custom-trigger]");

                if (trigger) {
                    const picker = trigger.closest("[data-custom-picker]");
                    const menu = picker.querySelector("[data-custom-menu]");
                    const opening = menu.hidden;

                    closeCustomPickers(picker);
                    menu.hidden = !opening;
                    picker.classList.toggle("is-open", opening);

                    if (opening) {
                        requestAnimationFrame(() => picker.querySelector("[data-custom-search]")?.focus());
                    }

                    return;
                }

                const choice = event.target.closest("[data-custom-option]");

                if (choice) {
                    const picker = choice.closest("[data-custom-picker]");
                    const select = picker.querySelector("[data-custom-previous]");

                    select.value = choice.dataset.value;
                    select.dispatchEvent(new Event("change", { bubbles: true }));
                    renderCustomPicker(choice.closest("[data-sale-item]"));
                    closeCustomPickers();
                }
            });

            container.addEventListener("input", event => {
                if (!event.target.matches("[data-custom-search]")) {
                    return;
                }

                const query = event.target.value.trim().toLowerCase();

                event.target.closest("[data-custom-picker]").querySelectorAll("[data-custom-option]").forEach(option => {
                    option.hidden = query !== "" && !(option.dataset.search || "").includes(query);
                });
            });

            document.addEventListener("click", event => {
                if (!event.target.closest("[data-custom-picker]")) {
                    closeCustomPickers();
                }
            });

            document.addEventListener("keydown", event => {
                if (event.key === "Escape") {
                    closeCustomPickers();
                }
            });

            function resetSource(row) {
                delete row.dataset.wholesaleApplied;
                delete row.dataset.retailPrice;
                row.querySelector("[data-source-select]").value = "inventory";
                row.querySelectorAll("[data-custom-input]").forEach(input => {
                    input.value = "";
                });
                row.querySelector("[data-custom-visibility]").value = "hidden";
                row.querySelectorAll("[data-custom-search]").forEach(search => {
                    search.value = "";
                });
                row.querySelectorAll("[data-custom-option]").forEach(option => {
                    option.hidden = false;
                });
                closeCustomPickers();
                renderCustomPicker(row);
                renderPhoto(row);
                applySource(row);
            }

            container.addEventListener("change", event => {
                if (event.target.matches("[data-source-select]")) {
                    applySource(event.target.closest("[data-sale-item]"));
                }

                if (event.target.matches("[data-custom-photo]")) {
                    renderPhoto(event.target.closest("[data-sale-item]"));
                }

                if (event.target.matches("[data-variant-select]")) {
                    applyChannelPrice(event.target.closest("[data-sale-item]"));
                }

                if (event.target.matches("[data-custom-previous]")) {
                    fillPrevious(event.target.closest("[data-sale-item]"), event.target);
                }
            });

            // Runs after the picker script's own handlers.
            container.addEventListener("click", event => {
                const removeButton = event.target.closest("[data-remove-item]");
                const row = removeButton?.closest("[data-sale-item]");

                if (row && row.isConnected) {
                    resetSource(row);
                }
            });

            addButton.addEventListener("click", () => {
                resetSource(container.lastElementChild);
            });

            container.querySelectorAll("[data-sale-item]").forEach(row => {
                applySource(row);
                renderPhoto(row);
                renderCustomPicker(row);
            });
        });
    </script>
    <script>
        /*
        |--------------------------------------------------------------------------
        | Stock across rows
        |--------------------------------------------------------------------------
        |
        | The same size can be in several rows, so each row only offers what
        | the other rows left: a size already taken shows "Ya en la venta" and
        | cannot be picked again, and the quantity cannot pass what is left.
        | The server checks the same thing when saving.
        |
        */
        document.addEventListener("DOMContentLoaded", () => {
            const form = document.getElementById("saleForm");
            const container = document.getElementById("saleItems");
            const tracksStock = () => (form.querySelector('input[name="doc_type"]:checked')?.value || "invoice") === "invoice";
            let timer = null;

            function inventoryRows() {
                return [...container.querySelectorAll("[data-sale-item]")].filter(row =>
                    row.querySelector("[data-source-select]").value !== "workshop");
            }

            function quantityOf(row) {
                const value = Number.parseInt(row.querySelector("[data-quantity-input]").value, 10);
                return Number.isFinite(value) && value > 0 ? value : 0;
            }

            function syncStock() {
                const rows = inventoryRows();
                const used = new Map();

                rows.forEach(row => {
                    const variant = row.querySelector("[data-variant-select]").value;

                    if (variant !== "") {
                        used.set(variant, (used.get(variant) || 0) + quantityOf(row));
                    }
                });

                const checkStock = tracksStock();

                rows.forEach(row => {
                    const select = row.querySelector("[data-variant-select]");
                    const quantityInput = row.querySelector("[data-quantity-input]");
                    const own = select.value;
                    const ownQuantity = quantityOf(row);

                    // Left for this row = stock minus what the other rows already take.
                    const leftFor = option => {
                        const stock = Number(option.dataset.stock || 0);
                        const others = (used.get(option.value) || 0) - (option.value === own ? ownQuantity : 0);
                        return stock - others;
                    };

                    select.querySelectorAll("option[value]:not([value=''])").forEach(option => {
                        const left = leftFor(option);
                        option.disabled = checkStock && left <= 0 && option.value !== own;
                    });

                    row.querySelectorAll("[data-picker-option]").forEach(button => {
                        const option = select.querySelector(`option[value="${button.dataset.value}"]`);

                        if (!option) {
                            return;
                        }

                        const stock = Number(option.dataset.stock || 0);
                        const left = checkStock ? leftFor(option) : stock;
                        const taken = checkStock && left <= 0 && option.value !== own && stock > 0;
                        const badge = button.querySelector(".sale-stock-badge");

                        button.disabled = stock <= 0 || taken;
                        button.classList.toggle("is-out-of-stock", button.disabled);

                        if (badge) {
                            badge.classList.toggle("is-empty", button.disabled);
                            badge.textContent = stock <= 0
                                ? "Agotado"
                                : taken
                                    ? "Ya en la venta"
                                    : `Stock ${Math.max(left, 0)}`;
                        }
                    });

                    renderSizeChips(row, select, checkStock ? leftFor : option => Number(option.dataset.stock || 0));

                    const ownOption = own !== "" ? select.querySelector(`option[value="${own}"]`) : null;
                    let warning = row.querySelector("[data-stock-warning]");

                    if (!ownOption || !checkStock) {
                        warning?.remove();
                        return;
                    }

                    const left = leftFor(ownOption);
                    quantityInput.max = String(Math.max(left, 0));

                    if (ownQuantity > left) {
                        if (!warning) {
                            warning = document.createElement("small");
                            warning.className = "form-hint sale-stock-warning";
                            warning.dataset.stockWarning = "";
                            quantityInput.insertAdjacentElement("afterend", warning);
                        }

                        warning.textContent = left > 0
                            ? `${left === 1 ? "Solo queda 1 par" : `Solo quedan ${left} pares`} de esta talla (contando las otras líneas).`
                            : "Esta talla ya está completa en otra línea.";
                    } else {
                        warning?.remove();
                    }
                });
            }

            /*
            | Other sizes of the chosen model: one click adds a pair of that
            | size (a new row, or +1 when the size is already in the sale).
            */
            function renderSizeChips(row, select, leftFor) {
                const box = row.querySelector("[data-size-chips]") || (() => {
                    const element = document.createElement("div");
                    element.className = "sale-size-chips";
                    element.dataset.sizeChips = "";
                    row.querySelector("[data-product-picker]").insertAdjacentElement("afterend", element);
                    return element;
                })();

                const own = select.selectedOptions[0];

                if (!own || own.value === "" || row.querySelector("[data-source-select]").value === "workshop") {
                    box.hidden = true;
                    box.innerHTML = "";
                    return;
                }

                const siblings = [...select.options].filter(option =>
                    option.value !== "" && option.dataset.code === own.dataset.code);
                const colors = new Set(siblings.map(option => option.dataset.color));

                // Only the first row of each model shows its sizes.
                const firstRow = inventoryRows().find(other =>
                    other.querySelector("[data-variant-select]").selectedOptions[0]?.dataset.code === own.dataset.code);

                box.hidden = siblings.length < 2 || firstRow !== row;
                box.innerHTML = "";

                if (box.hidden) {
                    return;
                }

                const label = document.createElement("span");
                label.className = "sale-size-chips-label";
                label.textContent = "Agregar otra talla:";
                box.appendChild(label);

                siblings.forEach(option => {
                    const left = leftFor(option) - (option.value === own.value ? quantityOf(row) : 0);
                    const chip = document.createElement("button");

                    chip.type = "button";
                    chip.className = "sale-size-chip";
                    chip.dataset.addVariant = option.value;
                    chip.disabled = left <= 0;
                    chip.title = left > 0 ? `Agregar 1 par (quedan ${left})` : "Sin stock disponible";
                    chip.innerHTML = "";

                    const size = document.createElement("strong");
                    size.textContent = (colors.size > 1 ? option.dataset.color + " " : "") + option.dataset.size;
                    const stock = document.createElement("small");
                    stock.textContent = left > 0 ? `+1 · quedan ${left}` : "agotado";
                    chip.append(size, stock);

                    box.appendChild(chip);
                });
            }

            function addVariant(variantId) {
                const rows = inventoryRows();
                const existing = rows.find(row => row.querySelector("[data-variant-select]").value === variantId);

                if (existing) {
                    const input = existing.querySelector("[data-quantity-input]");
                    input.value = String(quantityOf(existing) + 1);
                    input.dispatchEvent(new Event("input", { bubbles: true }));
                    return;
                }

                let target = rows.find(row => row.querySelector("[data-variant-select]").value === "");

                if (!target) {
                    document.getElementById("addSaleItem").click();
                    target = container.lastElementChild;
                }

                const select = target.querySelector("[data-variant-select]");
                select.value = variantId;
                select.dispatchEvent(new Event("change", { bubbles: true }));
            }

            container.addEventListener("click", event => {
                const chip = event.target.closest("[data-add-variant]");

                if (chip && !chip.disabled) {
                    addVariant(chip.dataset.addVariant);
                }
            });

            function schedule() {
                clearTimeout(timer);
                timer = setTimeout(syncStock, 0);
            }

            ["input", "change", "click"].forEach(type => form.addEventListener(type, schedule));

            // Saving with more than the stock is stopped here too.
            form.addEventListener("submit", event => {
                syncStock();

                const warning = container.querySelector("[data-stock-warning]");

                if (warning) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    warning.closest("[data-sale-item]").querySelector("[data-quantity-input]").focus();
                }
            });

            syncStock();
        });
    </script>
    <script>
        /*
        |--------------------------------------------------------------------------
        | Promotions and wholesale prices
        |--------------------------------------------------------------------------
        |
        | Finds what this sale qualifies for (wholesale price when a product
        | reaches its minimum, and the active promotions) and lists it. Nothing
        | changes a price until the user accepts it in the dialog, and saving
        | asks about the best pending offer first. One promotion per sale and
        | never together with wholesale prices. The server checks it again.
        |
        */
        document.addEventListener("DOMContentLoaded", () => {
            const form = document.getElementById("saleForm");
            const container = document.getElementById("saleItems");
            const section = document.getElementById("saleOffers");
            const promotionInput = document.getElementById("promotionId");
            const appliedBox = section.querySelector("[data-offers-applied]");
            const listBox = section.querySelector("[data-offers-list]");
            const notesBox = section.querySelector("[data-offers-notes]");
            const dialog = document.getElementById("saleOfferDialog");
            const grandTotal = document.getElementById("saleGrandTotal");
            const promotionLine = document.getElementById("salePromotionLine");
            const discountInput = document.getElementById("discount");
            const soldAtInput = document.getElementById("sold_at");
            const phoneInput = document.getElementById("customer_phone");
            const nameInput = document.getElementById("customer_name");
            const channelSelect = document.getElementById("channel");

            const promotions = <?= json_encode($salePromotions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
            const checkUrl = <?= json_encode(APP_BASE_URL . '/admin/sales/promotion-check.php', JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;
            const excludeId = <?= json_encode($editData !== null ? (int) $editId : null) ?>;
            const symbol = <?= json_encode(currency_symbol(), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;

            const money = value => symbol + value.toLocaleString("en-US", {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            const parse = value => {
                const number = Number.parseFloat(String(value ?? "").replace(/[^0-9.]/g, ""));
                return Number.isFinite(number) ? number : 0;
            };
            const round = value => Math.round(value * 100) / 100;
            const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;

            const rejected = new Set();
            let customer = { key: null, previous: null, loading: false, failed: false };
            let offers = [];
            let busy = false;
            let timer = null;

            /*
            | Rows of the sale with what each one needs.
            */
            function readLines() {
                return [...container.querySelectorAll("[data-sale-item]")].map(row => {
                    const isCustom = row.querySelector("[data-source-select]").value === "workshop";
                    const priceInput = row.querySelector("[data-price-input]");
                    const quantity = Math.max(0, Math.floor(parse(row.querySelector("[data-quantity-input]").value)));
                    const price = parse(priceInput.value);
                    let info;

                    if (isCustom) {
                        const name = row.querySelector('[name="items[custom_name][]"]').value.trim();

                        if (name === "") {
                            return null;
                        }

                        const code = row.querySelector('[name="items[custom_code][]"]').value.trim();
                        const model = code === ""
                            ? null
                            : [...row.querySelectorAll("[data-custom-previous] option")].find(option => option.value === code);

                        info = {
                            name,
                            code: code || null,
                            category: Number(row.querySelector('[name="items[custom_category][]"]').value || 0),
                            retail: model?.dataset.price ? parse(model.dataset.price) : null,
                            wholesale: model?.dataset.wholesalePrice ? parse(model.dataset.wholesalePrice) : null,
                            min: Number(model?.dataset.wholesaleMin || 0)
                        };
                    } else {
                        const option = row.querySelector("[data-variant-select]").selectedOptions[0];

                        if (!option || option.value === "") {
                            return null;
                        }

                        info = {
                            name: option.dataset.name || "Producto",
                            code: option.dataset.code || null,
                            category: Number(option.dataset.category || 0),
                            retail: option.dataset.price ? parse(option.dataset.price) : null,
                            wholesale: option.dataset.wholesalePrice ? parse(option.dataset.wholesalePrice) : null,
                            min: Number(option.dataset.wholesaleMin || 0)
                        };
                    }

                    const applied = row.dataset.wholesaleApplied === "1";

                    return {
                        row,
                        priceInput,
                        quantity,
                        price,
                        retailPrice: applied ? parse(row.dataset.retailPrice) : price,
                        applied,
                        ...info
                    };
                }).filter(Boolean);
            }

            function setPrice(line, value) {
                busy = true;
                line.priceInput.value = value.toFixed(2);
                line.priceInput.dispatchEvent(new Event("input", { bubbles: true }));
                busy = false;
            }

            function revertWholesale(line) {
                setPrice(line, line.retailPrice);
                delete line.row.dataset.wholesaleApplied;
                delete line.row.dataset.retailPrice;
            }

            /*
            | Wholesale: per product (all its sizes and colors together).
            */
            function wholesaleGroups(lines) {
                const groups = new Map();

                lines.forEach(line => {
                    if (!line.code || line.wholesale === null) {
                        return;
                    }

                    const group = groups.get(line.code) || {
                        name: line.name,
                        min: Math.max(line.min, 1),
                        wholesale: line.wholesale,
                        quantity: 0,
                        lines: []
                    };

                    group.quantity += line.quantity;
                    group.lines.push(line);
                    groups.set(line.code, group);
                });

                return [...groups.values()];
            }

            /*
            | Promotions: same rules as promotion_discount_for() in PHP.
            */
            function promotionResult(promotion, lines, useRetail) {
                const units = [];

                lines.forEach(line => {
                    // Models chosen one by one win over categories (promotion_applies_to() in PHP).
                    if (promotion.products && promotion.products.length > 0) {
                        if (!line.code || !promotion.products.includes(String(line.code).trim().toLowerCase())) {
                            return;
                        }
                    } else if (promotion.categories.length > 0 && !promotion.categories.includes(line.category)) {
                        return;
                    }

                    for (let i = 0; i < line.quantity; i++) {
                        units.push(useRetail ? line.retailPrice : line.price);
                    }
                });

                const base = units.reduce((sum, price) => sum + price, 0);

                if (promotion.type === "percent") {
                    const discount = round(base * promotion.percent / 100);

                    return {
                        discount,
                        detail: `${promotion.percent}% sobre ${plural(units.length, "producto", "productos")} (${money(base)}).`
                    };
                }

                const size = promotion.bundle_qty;

                if (size < 1 || units.length < size) {
                    return { discount: 0, detail: "" };
                }

                units.sort((a, b) => b - a);

                const bundles = Math.floor(units.length / size);
                const bundled = units.slice(0, bundles * size).reduce((sum, price) => sum + price, 0);
                const discount = Math.max(0, round(bundled - bundles * promotion.bundle_price));

                return {
                    discount,
                    detail: `${plural(bundles, "paquete", "paquetes")} de ${size}: ${money(bundled)} pasa a ${money(bundles * promotion.bundle_price)}.`
                };
            }

            function promotionStatus(promotion) {
                const date = (soldAtInput.value || "").slice(0, 10);

                if (!promotion.kept && date !== "") {
                    if ((promotion.starts_on && date < promotion.starts_on) || (promotion.ends_on && date > promotion.ends_on)) {
                        return "date";
                    }
                }

                if (promotion.first_purchase_only) {
                    if (customer.loading || customer.key !== customerKey()) {
                        return "checking";
                    }

                    if (customer.failed || customer.previous === null) {
                        return "need-customer";
                    }

                    if (customer.previous === true) {
                        return "returning";
                    }
                }

                return "ok";
            }

            /*
            | First purchase: asks the server whether the customer bought before.
            */
            function customerKey() {
                return phoneInput.value.trim() + "|" + nameInput.value.trim().toLowerCase();
            }

            async function checkCustomer() {
                const key = customerKey();

                if (key === "|") {
                    customer = { key, previous: null, loading: false, failed: false };
                    refresh();
                    return;
                }

                customer = { key, previous: null, loading: true, failed: false };
                refresh();

                const params = new URLSearchParams({
                    phone: phoneInput.value.trim(),
                    name: nameInput.value.trim()
                });

                if (excludeId) {
                    params.set("exclude", excludeId);
                }

                let result = { key, previous: null, loading: false, failed: true };

                try {
                    const response = await fetch(checkUrl + "?" + params.toString(), { credentials: "same-origin" });
                    const data = await response.json();

                    result = { key, previous: data.previous, loading: false, failed: false };
                } catch (error) {
                    // Leave failed: the promotion is not offered.
                }

                if (customerKey() === key) {
                    customer = result;
                    refresh();
                }
            }

            /*
            | Everything is recalculated from the form each time.
            */
            function refresh() {
                const notes = [];
                let lines = readLines();

                // Wholesale rows that no longer reach the minimum go back to the normal price.
                wholesaleGroups(lines).forEach(group => {
                    if (group.quantity >= group.min) {
                        return;
                    }

                    const appliedLines = group.lines.filter(line => line.applied);

                    if (appliedLines.length > 0) {
                        appliedLines.forEach(revertWholesale);
                        notes.push(`${group.name}: el precio mayorista es desde ${group.min} pares y lleva ${group.quantity}. Volvió al precio normal.`);
                    }
                });

                lines = readLines();

                const manual = parse(discountInput.value);
                const groups = wholesaleGroups(lines);
                const wholesaleApplied = lines.filter(line => line.applied);

                // The accepted promotion is kept only while it still applies.
                let applied = promotions.find(promotion => String(promotion.id) === promotionInput.value) || null;
                let appliedResult = { discount: 0, detail: "" };

                if (promotionInput.value !== "" && !applied) {
                    promotionInput.value = "";
                }

                if (applied) {
                    const status = promotionStatus(applied);
                    appliedResult = promotionResult(applied, lines, false);

                    if (status !== "checking" && (status !== "ok" || appliedResult.discount <= 0)) {
                        const reasons = {
                            date: "no está vigente en la fecha de la venta",
                            "need-customer": "es solo para la primera compra y falta el teléfono o nombre del cliente",
                            returning: "es solo para la primera compra y este cliente ya compró antes"
                        };

                        notes.push(`Se quitó la promoción "${applied.name}": ${reasons[status] || "ya no aplica a los productos de la venta"}.`);
                        promotionInput.value = "";
                        applied = null;
                        appliedResult = { discount: 0, detail: "" };
                    }
                }

                const subtotal = lines.reduce((sum, line) => sum + line.quantity * line.price, 0);
                const retailSubtotal = lines.reduce((sum, line) => sum + line.quantity * line.retailPrice, 0);
                const current = Math.max(round(subtotal - manual - appliedResult.discount), 0);

                grandTotal.textContent = money(current);

                if (applied) {
                    promotionLine.hidden = false;
                    promotionLine.textContent = `Incluye "${applied.name}": -${money(appliedResult.discount)}`;
                } else if (wholesaleApplied.length > 0) {
                    promotionLine.hidden = false;
                    promotionLine.textContent = "Con precio mayorista";
                } else {
                    promotionLine.hidden = true;
                }

                // Offers.
                offers = [];

                const eligible = groups.filter(group => group.quantity >= group.min);
                const wholesaleSaving = eligible.reduce((sum, group) =>
                    sum + group.lines.reduce((lineSum, line) =>
                        lineSum + Math.max(0, line.retailPrice - group.wholesale) * line.quantity, 0), 0);

                if (wholesaleSaving > 0) {
                    const after = Math.max(round(retailSubtotal - wholesaleSaving - manual), 0);

                    if (current - after > 0.004) {
                        offers.push({
                            key: "wholesale",
                            eyebrow: "¿APLICAR PRECIO MAYORISTA?",
                            title: "Precio mayorista",
                            detail: eligible.map(group =>
                                `${group.name}: ${plural(group.quantity, "par", "pares")} a ${money(group.wholesale)} c/u (mínimo ${group.min}).`
                            ).join(" "),
                            warning: applied ? `Se quita la promoción "${applied.name}": no se juntan.` : "",
                            after,
                            saving: round(current - after),
                            apply() {
                                promotionInput.value = "";
                                eligible.forEach(group => group.lines.forEach(line => {
                                    if (line.applied || line.retailPrice <= group.wholesale) {
                                        return;
                                    }

                                    line.row.dataset.retailPrice = line.retailPrice.toFixed(2);
                                    line.row.dataset.wholesaleApplied = "1";
                                    setPrice(line, group.wholesale);
                                }));
                            }
                        });
                    }
                }

                if (channelSelect.value === "wholesale") {
                    groups.filter(group => group.quantity < group.min).forEach(group => {
                        notes.push(`${group.name}: faltan ${group.min - group.quantity} pares para el precio mayorista (desde ${group.min}).`);
                    });
                }

                promotions.forEach(promotion => {
                    if (applied && promotion.id === applied.id) {
                        return;
                    }

                    const result = promotionResult(promotion, lines, true);

                    if (result.discount <= 0) {
                        return;
                    }

                    const status = promotionStatus(promotion);

                    if (status === "date") {
                        return;
                    }

                    if (status === "checking") {
                        notes.push(`Verificando si es la primera compra del cliente para "${promotion.name}"…`);
                        return;
                    }

                    if (status === "need-customer") {
                        notes.push(`"${promotion.name}" es solo para la primera compra: escribe el teléfono o el nombre del cliente para verificarlo.`);
                        return;
                    }

                    if (status === "returning") {
                        notes.push(`"${promotion.name}" no aplica: este cliente ya compró antes.`);
                        return;
                    }

                    const after = Math.max(round(retailSubtotal - manual - result.discount), 0);

                    if (current - after <= 0.004) {
                        return;
                    }

                    offers.push({
                        key: "promotion-" + promotion.id,
                        eyebrow: "¿APLICAR PROMOCIÓN?",
                        title: promotion.name,
                        detail: `${promotion.summary}. ${result.detail}`,
                        warning: [
                            wholesaleApplied.length > 0 ? "Se quita el precio mayorista: no se juntan." : "",
                            applied ? `Reemplaza la promoción "${applied.name}".` : ""
                        ].filter(Boolean).join(" "),
                        after,
                        saving: round(current - after),
                        apply() {
                            readLines().filter(line => line.applied).forEach(revertWholesale);
                            promotionInput.value = String(promotion.id);
                        }
                    });
                });

                offers.sort((a, b) => b.saving - a.saving);

                render(applied, appliedResult, wholesaleApplied, notes, current);
            }

            function render(applied, appliedResult, wholesaleApplied, notes, current) {
                appliedBox.innerHTML = "";
                listBox.innerHTML = "";
                notesBox.innerHTML = "";

                const card = (className, title, detail, side, button) => {
                    const element = document.createElement("div");
                    element.className = "sale-offer " + className;

                    const info = document.createElement("div");
                    info.className = "sale-offer-info";

                    const strong = document.createElement("strong");
                    strong.textContent = title;
                    const small = document.createElement("small");
                    small.textContent = detail;
                    info.append(strong, small);

                    const aside = document.createElement("div");
                    aside.className = "sale-offer-side";

                    if (side) {
                        const amount = document.createElement("span");
                        amount.className = "sale-offer-amount";
                        amount.textContent = side;
                        aside.appendChild(amount);
                    }

                    aside.appendChild(button);
                    element.append(info, aside);

                    return element;
                };

                const button = (label, data, outline = true) => {
                    const element = document.createElement("button");
                    element.type = "button";
                    element.className = "admin-button" + (outline ? " admin-button-outline" : "");
                    element.textContent = label;
                    Object.entries(data).forEach(([key, value]) => element.dataset[key] = value);
                    return element;
                };

                if (applied) {
                    appliedBox.appendChild(card(
                        "is-applied",
                        `Promoción aplicada: ${applied.name}`,
                        `${applied.summary}. ${appliedResult.detail}`,
                        `-${money(appliedResult.discount)}`,
                        button("QUITAR", { offerRemove: "promotion" })
                    ));
                }

                if (wholesaleApplied.length > 0) {
                    const names = [...new Set(wholesaleApplied.map(line => line.name))].join(", ");
                    const saving = wholesaleApplied.reduce((sum, line) => sum + (line.retailPrice - line.price) * line.quantity, 0);

                    appliedBox.appendChild(card(
                        "is-applied",
                        "Precio mayorista aplicado",
                        names,
                        `-${money(round(saving))}`,
                        button("QUITAR", { offerRemove: "wholesale" })
                    ));
                }

                offers.forEach((offer, index) => {
                    const element = card(
                        index === 0 && offers.length > 1 ? "is-best" : "",
                        offer.title + (index === 0 && offers.length > 1 ? " · la más conveniente" : ""),
                        offer.detail + (rejected.has(offer.key) ? " (no aplicada)" : ""),
                        `Total ${money(offer.after)} · ahorra ${money(offer.saving)}`,
                        button("APLICAR…", { offerKey: offer.key }, index !== 0)
                    );

                    listBox.appendChild(element);
                });

                notes.forEach(note => {
                    const item = document.createElement("li");
                    item.textContent = note;
                    notesBox.appendChild(item);
                });

                section.hidden = !applied && wholesaleApplied.length === 0 && offers.length === 0 && notes.length === 0;
            }

            /*
            | Confirmation dialog.
            */
            function confirmOffer(offer, onDone) {
                const current = grandTotal.textContent;

                dialog.querySelector("[data-dialog-eyebrow]").textContent = offer.eyebrow;
                dialog.querySelector("[data-dialog-title]").textContent = offer.title;
                dialog.querySelector("[data-dialog-detail]").textContent = offer.detail;
                dialog.querySelector("[data-dialog-before]").textContent = current;
                dialog.querySelector("[data-dialog-after]").textContent = money(offer.after);
                dialog.querySelector("[data-dialog-saving]").textContent = money(offer.saving);

                const warning = dialog.querySelector("[data-dialog-warning]");
                warning.textContent = offer.warning;
                warning.hidden = offer.warning === "";

                dialog.returnValue = "";
                dialog.onclose = () => {
                    // Escape closes without an answer: nothing changes and nothing is saved.
                    if (dialog.returnValue === "") {
                        refresh();
                        return;
                    }

                    if (dialog.returnValue === "accept") {
                        rejected.delete(offer.key);
                        offer.apply();
                    } else {
                        rejected.add(offer.key);
                    }

                    refresh();
                    onDone?.(dialog.returnValue === "accept");
                };

                if (typeof dialog.showModal === "function") {
                    dialog.showModal();
                } else {
                    dialog.returnValue = window.confirm(
                        `${offer.title}\n${offer.detail}\nNuevo total: ${money(offer.after)} (ahorra ${money(offer.saving)})\n\n¿Aplicar?`
                    ) ? "accept" : "reject";
                    dialog.onclose();
                }
            }

            section.addEventListener("click", event => {
                const apply = event.target.closest("[data-offer-key]");

                if (apply) {
                    const offer = offers.find(item => item.key === apply.dataset.offerKey);

                    if (offer) {
                        confirmOffer(offer);
                    }

                    return;
                }

                const remove = event.target.closest("[data-offer-remove]");

                if (!remove) {
                    return;
                }

                if (remove.dataset.offerRemove === "promotion") {
                    rejected.add("promotion-" + promotionInput.value);
                    promotionInput.value = "";
                } else {
                    readLines().filter(line => line.applied).forEach(revertWholesale);
                    rejected.add("wholesale");
                }

                refresh();
            });

            // Saving asks about the best offer nobody answered yet.
            form.addEventListener("submit", event => {
                refresh();

                const somethingApplied = promotionInput.value !== "" || readLines().some(line => line.applied);
                const pending = offers.filter(offer => !rejected.has(offer.key));

                if (somethingApplied || pending.length === 0) {
                    return;
                }

                event.preventDefault();
                section.hidden = false;

                confirmOffer(pending[0], () => {
                    if (typeof form.requestSubmit === "function") {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                });
            });

            function schedule() {
                if (busy) {
                    return;
                }

                clearTimeout(timer);
                timer = setTimeout(refresh, 0);
            }

            ["input", "change", "click"].forEach(type => form.addEventListener(type, schedule));

            let customerTimer = null;

            [phoneInput, nameInput].forEach(input => input.addEventListener("input", () => {
                if (!promotions.some(promotion => promotion.first_purchase_only)) {
                    return;
                }

                clearTimeout(customerTimer);
                customerTimer = setTimeout(checkCustomer, 400);
            }));

            // Rows that come back with their wholesale price (edit, or a save error).
            readLines().forEach(line => {
                if (line.wholesale !== null && line.retail !== null && line.retail > line.wholesale &&
                    Math.abs(line.price - line.wholesale) < 0.005) {
                    line.row.dataset.retailPrice = line.retail.toFixed(2);
                    line.row.dataset.wholesaleApplied = "1";
                }
            });

            if (promotions.some(promotion => promotion.first_purchase_only)) {
                checkCustomer();
            } else {
                refresh();
            }
        });
    </script>
<?php endif; ?>
<?php render_sales_layout_end(); ?>