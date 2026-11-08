<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/workshop-functions.php';
require_once __DIR__ . '/../../includes/order-functions.php';
require_once __DIR__ . '/../sales/_layout.php';

require_admin();

$tablesReady = workshop_tables_exist($pdo);
$products = $tablesReady ? workshop_products($pdo) : [];
$error = '';
$hadNewPhotos = false;

foreach ((array) ($_FILES['models']['error'] ?? []) as $uploadErrors) {
    if (is_array($uploadErrors) && ($uploadErrors['photo'] ?? null) === UPLOAD_ERR_OK) {
        $hadNewPhotos = true;
    }
}

$form = [
    'reference' => '',
    'sale_id' => '',
    'sale_number' => '',
    'due_date' => '',
    'notes' => '',
    'models' => [workshop_empty_model()],
];


/*
|--------------------------------------------------------------------------
| Start From A Sale
|--------------------------------------------------------------------------
*/

$saleId = filter_var($_GET['sale_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (
    $tablesReady &&
    $_SERVER['REQUEST_METHOD'] !== 'POST' &&
    $saleId !== false &&
    sales_tables_exist($pdo)
) {
    $form = workshop_form_from_sale($pdo, $saleId) ?? $form;
}

/*
| From a catalog order (?from_order=ID, admin/orders/). Saving links the
| order to the new workshop order.
*/
$fromOrderId = filter_var($_GET['from_order'] ?? $_POST['from_order'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$fromOrder = $tablesReady && $fromOrderId !== false && orders_tables_exist($pdo) ? load_order($pdo, $fromOrderId) : null;

if ($fromOrder !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $form = order_workshop_form($fromOrder);
}


/*
|--------------------------------------------------------------------------
| Save
|--------------------------------------------------------------------------
*/

if ($tablesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['reference', 'sale_id', 'sale_number', 'due_date', 'notes'] as $field) {
        $value = $_POST[$field] ?? '';
        $form[$field] = is_string($value) ? trim($value) : '';
    }

    $form['models'] = workshop_models_from_post($_POST['models'] ?? []);

    if ($form['models'] === []) {
        $form['models'] = [workshop_empty_model()];
    }

    $dueDate = $form['due_date'] !== '' ? DateTime::createFromFormat('Y-m-d', $form['due_date']) : null;
    $linkedSaleId = filter_var($form['sale_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $cleanModels = [];

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido. Recarga la página.';
    } elseif ($dueDate === false || ($dueDate !== null && $dueDate->format('Y-m-d') !== $form['due_date'])) {
        $error = 'La fecha de entrega no es válida.';
    } else {
        [$cleanModels, $error] = validate_workshop_models($form['models'], $products);
    }

    if ($error === '') {
        $uploaded = [];

        try {
            foreach ($cleanModels as &$model) {
                $path = upload_workshop_model_image(workshop_uploaded_file($model['form_key']));

                if ($path !== null) {
                    $uploaded[] = $path;
                    $model['image_path'] = $path;
                }
            }
            unset($model);

            $pdo->beginTransaction();

            $pdo->prepare(
                'INSERT INTO workshop_orders (reference, sale_id, due_date, status, notes, admin_id)
                 VALUES (:reference, :sale_id, :due_date, "pending", :notes, :admin_id)'
            )->execute([
                'reference' => $form['reference'] !== '' ? mb_substr($form['reference'], 0, 150) : null,
                'sale_id' => $linkedSaleId !== false ? $linkedSaleId : null,
                'due_date' => $dueDate?->format('Y-m-d'),
                'notes' => $form['notes'] !== '' ? mb_substr($form['notes'], 0, 2000) : null,
                'admin_id' => isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null,
            ]);

            $orderId = (int) $pdo->lastInsertId();

            $insertModel = $pdo->prepare(
                'INSERT INTO workshop_order_models
                    (order_id, product_id, product_code, product_name, image_path, notes, sort_order)
                 VALUES
                    (:order_id, :product_id, :product_code, :product_name, :image_path, :notes, :sort_order)'
            );
            $insertLine = $pdo->prepare(
                'INSERT INTO workshop_order_lines (model_id, color_name, size, quantity)
                 VALUES (:model_id, :color_name, :size, :quantity)'
            );

            foreach ($cleanModels as $sortOrder => $model) {
                $insertModel->execute([
                    'order_id' => $orderId,
                    'product_id' => $model['product_id'],
                    'product_code' => $model['code'] !== '' ? $model['code'] : null,
                    'product_name' => $model['name'],
                    'image_path' => $model['image_path'] !== '' ? $model['image_path'] : null,
                    'notes' => $model['notes'] !== '' ? $model['notes'] : null,
                    'sort_order' => $sortOrder,
                ]);

                $modelId = (int) $pdo->lastInsertId();

                foreach ($model['lines'] as $line) {
                    $insertLine->execute([
                        'model_id' => $modelId,
                        'color_name' => $line['color'],
                        'size' => $line['size'],
                        'quantity' => $line['quantity'],
                    ]);
                }
            }

            if ($fromOrder !== null) {
                link_order_to_workshop($pdo, (int) $fromOrder['id'], $orderId);
            }

            $pdo->commit();

            header('Location: ' . APP_BASE_URL . '/admin/workshop/view.php?id=' . $orderId . '&created=1');
            exit;
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Workshop order save failed: ' . $exception->getMessage());
            $error = 'No se pudo guardar el pedido. Inténtalo de nuevo.';
        }

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($uploaded as $path) {
            delete_workshop_model_image($path);
        }
    }
}

$sizes = workshop_sizes_for($form['models']);

render_sales_layout_start('Nuevo pedido al taller', 'workshop');

?>

<div class="page-header">
    <div>
        <span class="page-eyebrow">TALLER</span>
        <h1 class="page-title">Nuevo pedido al taller</h1>
        <p class="page-description">
            Elige cada modelo y anota cuántos pares van por color y talla.
            Al guardar se genera el documento para imprimir o enviar al taller.
        </p>
    </div>

    <a class="admin-button admin-button-outline" href="<?= e(APP_BASE_URL) ?>/admin/workshop/">
        ← VOLVER A PEDIDOS
    </a>
</div>

<?php if (!$tablesReady): ?>

    <?php render_workshop_migration_notice(); ?>

<?php else: ?>

    <?php if ($error !== ''): ?>
        <div class="admin-notice admin-notice-error">
            <?= e($error) ?>
            <?php if ($hadNewPhotos): ?>
                Vuelve a elegir las fotos nuevas.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form class="workshop-form" method="post" enctype="multipart/form-data" data-workshop-form>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="sale_id" value="<?= e($form['sale_id']) ?>">
        <?php if ($fromOrder !== null): ?>
            <input type="hidden" name="from_order" value="<?= (int) $fromOrder['id'] ?>">
        <?php endif; ?>
        <input type="hidden" name="sale_number" value="<?= e($form['sale_number']) ?>">

        <section class="form-section">
            <div class="form-section-header">
                <h2>Datos del pedido</h2>
                <?php if ($form['sale_number'] !== ''): ?>
                    <p>Basado en la venta <?= e($form['sale_number']) ?>.</p>
                <?php endif; ?>
            </div>

            <div class="form-grid workshop-order-grid">
                <div class="form-group">
                    <label class="form-label" for="reference">Cliente o referencia (opcional)</label>
                    <input class="form-input" type="text" id="reference" name="reference" maxlength="150"
                        value="<?= e($form['reference']) ?>" placeholder="Ej.: Tienda Masaya / reposición">
                </div>
                <div class="form-group">
                    <label class="form-label" for="due_date">Fecha de entrega (opcional)</label>
                    <input class="form-input" type="date" id="due_date" name="due_date"
                        value="<?= e($form['due_date']) ?>">
                </div>
                <div class="form-group form-group-full">
                    <label class="form-label" for="notes">Notas generales (opcional)</label>
                    <textarea class="form-textarea" id="notes" name="notes" rows="2"
                        maxlength="2000"><?= e($form['notes']) ?></textarea>
                </div>
            </div>
        </section>

        <section class="form-section">
            <div class="form-section-header">
                <h2>Modelos</h2>
            </div>

            <div class="workshop-models" data-models>
                <?php foreach ($form['models'] as $index => $model): ?>
                    <?php render_workshop_model_block((string) $index, $model, $products, $sizes); ?>
                <?php endforeach; ?>
            </div>

            <div class="add-color-wrapper">
                <button class="admin-button admin-button-outline" type="button" data-add-model>
                    + AGREGAR MODELO
                </button>
            </div>
        </section>

        <div class="form-actions workshop-form-actions">
            <span class="workshop-grand-total">Total del pedido: <strong data-grand-total>0</strong> pares</span>
            <button class="admin-button" type="submit">GUARDAR Y VER DOCUMENTO</button>
        </div>
    </form>

    <template data-model-template>
        <?php render_workshop_model_block('__MODEL__', workshop_empty_model(), $products, $sizes); ?>
    </template>

    <template data-color-template>
        <?php render_workshop_color_row('__MODEL__', '__COLOR__', ['name' => '', 'qty' => []], $sizes); ?>
    </template>

    <script>
        (() => {
            const form = document.querySelector('[data-workshop-form]');
            const models = form.querySelector('[data-models]');
            const modelTemplate = document.querySelector('[data-model-template]').innerHTML;
            const colorTemplate = document.querySelector('[data-color-template]').innerHTML;
            // Unique keys, so re-rendered rows after an error never collide.
            let nextKey = Date.now();

            const fromHtml = (html) => {
                const holder = document.createElement('div');
                holder.innerHTML = html.trim();
                return holder.firstElementChild;
            };

            const refreshTotals = () => {
                let grand = 0;

                models.querySelectorAll('[data-model]').forEach((model, position) => {
                    let total = 0;
                    model.querySelectorAll('[data-qty]').forEach((input) => {
                        total += Math.max(0, parseInt(input.value, 10) || 0);
                    });
                    model.querySelector('[data-model-total]').textContent = total;
                    model.querySelector('[data-model-number]').textContent = position + 1;
                    grand += total;
                });

                form.querySelector('[data-grand-total]').textContent = grand;
            };

            const showPreview = (model, src) => {
                const image = model.querySelector('[data-model-preview]');
                const empty = model.querySelector('[data-model-preview-empty]');
                image.hidden = !src;
                empty.hidden = !!src;
                if (src) {
                    image.src = src;
                } else {
                    image.removeAttribute('src');
                }
            };

            const productChanged = (model) => {
                const option = model.querySelector('[data-model-product]').selectedOptions[0];
                const isCatalog = option && option.value !== '';
                const list = model.querySelector('[data-color-list]');

                model.querySelector('[data-model-new]').hidden = isCatalog;
                model.querySelector('[data-model-image-path]').value = '';
                model.querySelector('[data-model-photo]').value = '';
                showPreview(model, isCatalog ? option.dataset.image : '');

                list.innerHTML = '';
                (isCatalog ? option.dataset.colors.split('|') : []).filter(Boolean).forEach((color) => {
                    const item = document.createElement('option');
                    item.value = color;
                    list.appendChild(item);
                });
            };

            form.querySelector('[data-add-model]').addEventListener('click', () => {
                const model = fromHtml(modelTemplate.replaceAll('__MODEL__', 'n' + nextKey++));
                models.appendChild(model);
                refreshTotals();
                model.querySelector('[data-model-product]').focus();
            });

            models.addEventListener('click', (event) => {
                const model = event.target.closest('[data-model]');

                if (event.target.closest('[data-remove-model]')) {
                    if (models.querySelectorAll('[data-model]').length > 1) {
                        model.remove();
                    } else {
                        model.querySelectorAll('input:not([type=hidden]), select, textarea').forEach((field) => {
                            field.value = '';
                        });
                        productChanged(model);
                    }
                    refreshTotals();
                }

                if (event.target.closest('[data-add-color]')) {
                    const html = colorTemplate
                        .replaceAll('__MODEL__', model.dataset.index)
                        .replaceAll('__COLOR__', 'c' + nextKey++);
                    const row = fromHtml(html);
                    model.querySelector('[data-color-rows]').appendChild(row);
                    row.querySelector('[data-color-name]').focus();
                }

                if (event.target.closest('[data-remove-color]')) {
                    const rows = model.querySelector('[data-color-rows]');
                    const row = event.target.closest('[data-color-row]');
                    if (rows.children.length > 1) {
                        row.remove();
                    } else {
                        row.querySelectorAll('input').forEach((input) => {
                            input.value = '';
                        });
                    }
                    refreshTotals();
                }
            });

            models.addEventListener('change', (event) => {
                const model = event.target.closest('[data-model]');

                if (event.target.matches('[data-model-product]')) {
                    productChanged(model);
                }

                if (event.target.matches('[data-model-photo]') && event.target.files[0]) {
                    showPreview(model, URL.createObjectURL(event.target.files[0]));
                }
            });

            models.addEventListener('input', (event) => {
                if (event.target.matches('[data-qty]')) {
                    refreshTotals();
                }
            });

            refreshTotals();
        })();
    </script>

<?php endif; ?>

<?php render_sales_layout_end(); ?>
