<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/product-functions.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/wholesale-functions.php';
require_once __DIR__ . '/../../includes/finance-functions.php';
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
                    p.name
                 FROM product_variants pv
                 INNER JOIN products p
                    ON p.id = pv.product_id
                 WHERE pv.id = :id
                 FOR UPDATE'
            );

            $lineItems = [];
            $requestedByVariant = [];
            $subtotal = 0.0;

            foreach ($requestedItems as $item) {
                if ($item['source'] === 'workshop') {
                    $lineTotal = round(
                        $item['quantity'] * $item['unit_price'],
                        2
                    );

                    $subtotal += $lineTotal;

                    $madeToOrderProduct = save_made_to_order_product($pdo, $item);
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

            if ($discount > $subtotal) {
                throw new DomainException(
                    'El descuento no puede ser mayor que el subtotal.'
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

// Earlier 'por encargo' products, to pick them again.
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
                                    <select class="form-select sale-custom-previous" data-custom-input data-custom-previous>
                                        <option value="">Producto nuevo (o elige uno ya encargado)</option>
                                        <?php foreach ($madeToOrderProducts as $previousProduct): ?>
                                            <option value="<?= e($previousProduct['code']) ?>"
                                                data-name="<?= e($previousProduct['name']) ?>"
                                                data-category="<?= (int) $previousProduct['category_id'] ?>"
                                                data-description="<?= e($previousProduct['description'] ?? '') ?>"
                                                data-cost="<?= e(admin_can_see_costs() ? ($previousProduct['cost_price'] ?? '') : '') ?>"
                                                data-price="<?= e($previousProduct['price'] ?? '') ?>">
                                                <?= e($previousProduct['name']) ?> · <?= e($previousProduct['code']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
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
                                            ?>" data-wholesale-price="<?= e($option['wholesale_price']) ?>" data-wholesale-min="<?= (int) $option['wholesale_min_qty'] ?>" data-image="<?= e(
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

        <div class="inventory-save-bar">
            <div>
                <span class="inventory-save-label">Total de la venta</span>
                <strong id="saleGrandTotal"><?= e(currency_symbol()) ?>0.00</strong>
            </div>
            <button class="admin-button" type="submit">
                <?= $editData !== null ? 'GUARDAR CAMBIOS' : 'GUARDAR' ?>
            </button>
        </div>
    </form>

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

            // Mayorista channel: use the product's wholesale price when it has one.
            const channelSelect = document.getElementById("channel");

            function applyChannelPrice(row) {
                const option = row.querySelector("[data-variant-select]").selectedOptions[0];
                const priceInput = row.querySelector("[data-price-input]");

                if (!option || option.value === "" || !option.dataset.wholesalePrice) {
                    return;
                }

                const isWholesale = channelSelect.value === "wholesale";

                priceInput.value = isWholesale
                    ? option.dataset.wholesalePrice
                    : option.dataset.price;

                const minimum = Number(option.dataset.wholesaleMin || 0);

                priceInput.title = isWholesale && minimum > 1
                    ? `Precio mayorista desde ${minimum} pares`
                    : "";

                priceInput.dispatchEvent(new Event("input", { bubbles: true }));
            }

            channelSelect.addEventListener("change", () => {
                container.querySelectorAll("[data-sale-item]").forEach(applyChannelPrice);
            });

            function fillPrevious(row, select) {
                const option = select.selectedOptions[0];

                if (!option || option.value === "") {
                    return;
                }

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

            function resetSource(row) {
                row.querySelector("[data-source-select]").value = "inventory";
                row.querySelectorAll("[data-custom-input]").forEach(input => {
                    input.value = "";
                });
                row.querySelector("[data-custom-visibility]").value = "hidden";
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
            });
        });
    </script>
<?php endif; ?>
<?php render_sales_layout_end(); ?>