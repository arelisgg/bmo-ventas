<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/promotion-functions.php';
require_once __DIR__ . '/../sales/_layout.php';

require_admin();

$tablesReady = promotion_tables_exist($pdo);
$imagesReady = $tablesReady && promotion_images_ready($pdo);
$types = promotion_types();
$error = '';

$categories = $pdo->query(
    'SELECT id, name
     FROM categories
     ORDER BY sort_order ASC, name ASC'
)->fetchAll();

$categoryNames = array_column($categories, 'name', 'id');

$emptyForm = [
    'id' => '',
    'name' => '',
    'type' => 'bundle',
    'bundle_qty' => '3',
    'bundle_price' => '',
    'percent' => '',
    'first_purchase_only' => false,
    'starts_on' => '',
    'ends_on' => '',
    'is_active' => true,
    'categories' => [],
    'image_path' => '',
];

$form = $emptyForm;


/*
|--------------------------------------------------------------------------
| Save / Toggle / Delete
|--------------------------------------------------------------------------
*/

if ($tablesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $promotionId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido.';
    } elseif ($action === 'delete' && $promotionId !== false) {
        if ($imagesReady) {
            $image = $pdo->prepare('SELECT image_path FROM promotions WHERE id = :id');
            $image->execute(['id' => $promotionId]);
            delete_promotion_image($image->fetchColumn() ?: null);
        }

        $pdo->prepare('DELETE FROM promotions WHERE id = :id')->execute(['id' => $promotionId]);

        header('Location: ' . APP_BASE_URL . '/admin/promotions/?deleted=1');
        exit;
    } elseif ($action === 'toggle' && $promotionId !== false) {
        $pdo->prepare('UPDATE promotions SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $promotionId]);

        header('Location: ' . APP_BASE_URL . '/admin/promotions/?updated=1');
        exit;
    } elseif ($action === 'save') {
        foreach (['name', 'type', 'bundle_qty', 'bundle_price', 'percent', 'starts_on', 'ends_on'] as $field) {
            $value = $_POST[$field] ?? '';
            $form[$field] = is_string($value) ? trim($value) : '';
        }

        $form['id'] = $promotionId !== false ? (string) $promotionId : '';
        $form['first_purchase_only'] = ($_POST['first_purchase_only'] ?? '') === '1';
        $form['is_active'] = ($_POST['is_active'] ?? '') === '1';
        $form['categories'] = array_values(array_filter(
            array_map('intval', is_array($_POST['categories'] ?? null) ? $_POST['categories'] : []),
            static fn (int $id): bool => isset($categoryNames[$id])
        ));

        $values = [
            'name' => mb_substr($form['name'], 0, 150),
            'type' => $form['type'],
            'bundle_qty' => null,
            'bundle_price' => null,
            'percent' => null,
            'first_purchase_only' => $form['first_purchase_only'] ? 1 : 0,
            'starts_on' => $form['starts_on'] !== '' ? $form['starts_on'] : null,
            'ends_on' => $form['ends_on'] !== '' ? $form['ends_on'] : null,
            'is_active' => $form['is_active'] ? 1 : 0,
        ];

        $validDate = static function (string $date): bool {
            $parsed = DateTime::createFromFormat('Y-m-d', $date);

            return $parsed !== false && $parsed->format('Y-m-d') === $date;
        };

        if ($form['name'] === '') {
            $error = 'Escribe el nombre de la promoción.';
        } elseif (!isset($types[$form['type']])) {
            $error = 'Elige el tipo de promoción.';
        } elseif ($form['type'] === 'bundle') {
            $quantity = filter_var($form['bundle_qty'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
            $price = parse_money_input($form['bundle_price']);

            if ($quantity === false) {
                $error = 'La cantidad del paquete debe ser 2 o más productos.';
            } elseif ($price === null || $price <= 0) {
                $error = 'Ingresa el precio del paquete.';
            } else {
                $values['bundle_qty'] = $quantity;
                $values['bundle_price'] = number_format($price, 2, '.', '');
            }
        } else {
            $percent = str_replace(',', '.', $form['percent']);

            if (!is_numeric($percent) || (float) $percent <= 0 || (float) $percent > 100) {
                $error = 'El porcentaje debe estar entre 1 y 100.';
            } else {
                $values['percent'] = number_format((float) $percent, 2, '.', '');
            }
        }

        if ($error === '' && (($values['starts_on'] !== null && !$validDate($values['starts_on'])) ||
            ($values['ends_on'] !== null && !$validDate($values['ends_on'])))) {
            $error = 'Revisa las fechas de vigencia.';
        }

        if ($error === '' && $values['starts_on'] !== null && $values['ends_on'] !== null &&
            $values['ends_on'] < $values['starts_on']) {
            $error = 'La fecha final no puede ser antes de la inicial.';
        }

        $currentImage = null;
        $newImage = null;

        if ($error === '' && $imagesReady) {
            if ($form['id'] !== '') {
                $image = $pdo->prepare('SELECT image_path FROM promotions WHERE id = :id');
                $image->execute(['id' => (int) $form['id']]);
                $currentImage = $image->fetchColumn() ?: null;
                $form['image_path'] = (string) $currentImage;
            }

            try {
                $newImage = upload_promotion_image($_FILES['image'] ?? []);
            } catch (DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        if ($error === '') {
            $pdo->beginTransaction();

            try {
                if ($form['id'] !== '') {
                    $pdo->prepare(
                        'UPDATE promotions
                         SET name = :name, type = :type, bundle_qty = :bundle_qty, bundle_price = :bundle_price,
                             percent = :percent, first_purchase_only = :first_purchase_only,
                             starts_on = :starts_on, ends_on = :ends_on, is_active = :is_active
                         WHERE id = :id'
                    )->execute($values + ['id' => (int) $form['id']]);

                    $savedId = (int) $form['id'];
                } else {
                    $pdo->prepare(
                        'INSERT INTO promotions
                            (name, type, bundle_qty, bundle_price, percent, first_purchase_only, starts_on, ends_on, is_active)
                         VALUES
                            (:name, :type, :bundle_qty, :bundle_price, :percent, :first_purchase_only, :starts_on, :ends_on, :is_active)'
                    )->execute($values);

                    $savedId = (int) $pdo->lastInsertId();
                }

                $pdo->prepare('DELETE FROM promotion_categories WHERE promotion_id = :id')->execute(['id' => $savedId]);

                $insertCategory = $pdo->prepare(
                    'INSERT INTO promotion_categories (promotion_id, category_id) VALUES (:promotion_id, :category_id)'
                );

                foreach ($form['categories'] as $categoryId) {
                    $insertCategory->execute(['promotion_id' => $savedId, 'category_id' => $categoryId]);
                }

                $removeImage = ($_POST['remove_image'] ?? '') === '1';

                if ($imagesReady && ($newImage !== null || $removeImage)) {
                    $pdo->prepare('UPDATE promotions SET image_path = :image_path WHERE id = :id')->execute([
                        'image_path' => $newImage,
                        'id' => $savedId,
                    ]);
                }

                $pdo->commit();

                if ($newImage !== null || $removeImage) {
                    delete_promotion_image($currentImage);
                }
            } catch (Throwable $exception) {
                $pdo->rollBack();
                delete_promotion_image($newImage);
                error_log($exception->getMessage());
                $error = 'No se pudo guardar.';
            }

            if ($error === '') {
                header('Location: ' . APP_BASE_URL . '/admin/promotions/?' . ($form['id'] !== '' ? 'updated' : 'created') . '=1');
                exit;
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| List / Edit
|--------------------------------------------------------------------------
*/

$promotions = load_promotions($pdo);

$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($tablesReady && $editId !== false && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach ($promotions as $promotion) {
        if ((int) $promotion['id'] !== $editId) {
            continue;
        }

        $form = [
            'id' => (string) $promotion['id'],
            'name' => (string) $promotion['name'],
            'type' => (string) $promotion['type'],
            'bundle_qty' => (string) ($promotion['bundle_qty'] ?? '3'),
            'bundle_price' => (string) ($promotion['bundle_price'] ?? ''),
            'percent' => $promotion['percent'] !== null ? rtrim(rtrim((string) $promotion['percent'], '0'), '.') : '',
            'first_purchase_only' => (int) $promotion['first_purchase_only'] === 1,
            'starts_on' => (string) ($promotion['starts_on'] ?? ''),
            'ends_on' => (string) ($promotion['ends_on'] ?? ''),
            'is_active' => (int) $promotion['is_active'] === 1,
            'categories' => array_keys($promotion['categories']),
            'image_path' => (string) ($promotion['image_path'] ?? ''),
        ];
    }
}

$today = date('Y-m-d');

render_sales_layout_start('Promociones', 'promotions');

?>

<div class="page-header">
    <div>
        <span class="page-eyebrow">VENTAS</span>
        <h1 class="page-title">Promociones</h1>
        <p class="page-description">
            Al registrar una venta el sistema avisa qué promoción aplica y el nuevo precio,
            y solo la aplica si la aceptas. Una venta lleva una sola promoción y no se junta con el precio mayorista:
            se ofrece la opción más favorable para el cliente.
        </p>
    </div>

    <a class="admin-button admin-button-outline" href="<?= e(APP_BASE_URL) ?>/admin/sales/">
        VOLVER A VENTAS
    </a>
</div>

<?php if (!$tablesReady): ?>

    <section class="empty-state">
        <h2>Falta crear las tablas de promociones</h2>
        <p>
            En <a href="<?= e(APP_BASE_URL) ?>/admin/settings/">Configuración</a> pulsa
            <strong>ACTUALIZAR BASE DE DATOS</strong> (solo el dueño puede hacerlo). Luego recarga esta página.
        </p>
    </section>

<?php else: ?>

    <?php if (isset($_GET['created'])): ?>
        <div class="admin-notice admin-notice-success">Promoción creada.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="admin-notice admin-notice-success">Promoción actualizada.</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="admin-notice admin-notice-success">Promoción eliminada.</div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="admin-notice admin-notice-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$imagesReady): ?>
        <div class="admin-notice">
            Para poner una imagen a cada promoción, pulsa <strong>ACTUALIZAR BASE DE DATOS</strong> en
            <a href="<?= e(APP_BASE_URL) ?>/admin/settings/">Configuración</a>.
        </div>
    <?php endif; ?>

    <section class="dashboard-panel finance-panel">
        <div class="dashboard-panel-header">
            <div>
                <span class="page-eyebrow"><?= $form['id'] !== '' ? 'EDITAR' : 'NUEVA PROMOCIÓN' ?></span>
                <h2><?= $form['id'] !== '' ? e($form['name']) : 'Crear promoción' ?></h2>
            </div>
            <?php if ($form['id'] !== ''): ?>
                <a class="table-action-link" href="<?= e(APP_BASE_URL) ?>/admin/promotions/">Cancelar</a>
            <?php endif; ?>
        </div>

        <form class="promotion-form" method="post" enctype="multipart/form-data" data-promotion-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e($form['id']) ?>">

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="promotion_name">Nombre</label>
                    <input class="form-input" id="promotion_name" name="name" maxlength="150" required
                        value="<?= e($form['name']) ?>" placeholder="Ej.: 3 flats por C$1000">
                </div>

                <div class="form-group">
                    <label class="form-label" for="promotion_type">Tipo</label>
                    <select class="form-select" id="promotion_type" name="type" data-promotion-type>
                        <?php foreach ($types as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $form['type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" data-promotion-field="bundle">
                    <label class="form-label" for="bundle_qty">Cantidad de productos</label>
                    <input class="form-input" type="number" id="bundle_qty" name="bundle_qty" min="2" step="1"
                        value="<?= e($form['bundle_qty']) ?>">
                </div>

                <div class="form-group" data-promotion-field="bundle">
                    <label class="form-label" for="bundle_price">Precio del paquete (<?= e(currency_symbol()) ?>)</label>
                    <input class="form-input" type="number" id="bundle_price" name="bundle_price" min="0.01" step="0.01"
                        value="<?= e($form['bundle_price']) ?>" placeholder="1000">
                </div>

                <div class="form-group" data-promotion-field="percent">
                    <label class="form-label" for="percent">Descuento (%)</label>
                    <input class="form-input" type="number" id="percent" name="percent" min="1" max="100" step="0.01"
                        value="<?= e($form['percent']) ?>" placeholder="10">
                </div>

                <div class="form-group">
                    <label class="form-label" for="starts_on">Desde (opcional)</label>
                    <input class="form-input" type="date" id="starts_on" name="starts_on" value="<?= e($form['starts_on']) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="ends_on">Hasta (opcional)</label>
                    <input class="form-input" type="date" id="ends_on" name="ends_on" value="<?= e($form['ends_on']) ?>">
                </div>

                <?php if ($imagesReady): ?>
                    <div class="form-group form-group-full">
                        <span class="form-label">Imagen para el catálogo (opcional)</span>
                        <div class="promotion-image-field">
                            <?php if ($form['image_path'] !== ''): ?>
                                <img class="promotion-image-preview" src="<?= e(APP_BASE_URL . '/' . ltrim($form['image_path'], '/')) ?>" alt="">
                            <?php endif; ?>
                            <div>
                                <input class="form-input" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                                <?php if ($form['image_path'] !== ''): ?>
                                    <label class="checkbox-label promotion-image-remove">
                                        <input type="checkbox" name="remove_image" value="1"> Quitar la imagen
                                    </label>
                                <?php endif; ?>
                                <p class="form-hint">
                                    Las promociones activas con imagen se muestran en el catálogo, en la sección Promociones.
                                    Se muestra en una tarjeta horizontal junto al texto; sirve vertical u horizontal, hasta 10 MB.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-group form-group-full">
                    <span class="form-label">Categorías donde aplica</span>
                    <div class="checkbox-row">
                        <?php foreach ($categories as $category): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>"
                                    <?= in_array((int) $category['id'], $form['categories'], true) ? 'checked' : '' ?>>
                                <?= e($category['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="form-hint">Si no marcas ninguna, aplica a todos los productos.</p>
                </div>

                <div class="form-group form-group-full">
                    <div class="checkbox-row">
                        <label class="checkbox-label">
                            <input type="checkbox" name="first_purchase_only" value="1" <?= $form['first_purchase_only'] ? 'checked' : '' ?>>
                            Solo en la primera compra del cliente
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_active" value="1" <?= $form['is_active'] ? 'checked' : '' ?>>
                            Activa
                        </label>
                    </div>
                    <p class="form-hint">
                        La primera compra se verifica con el teléfono del cliente (o su nombre si no hay teléfono):
                        no debe tener otra factura que no esté cancelada.
                    </p>
                </div>
            </div>

            <div class="finance-expense-actions">
                <button class="admin-button" type="submit"><?= $form['id'] !== '' ? 'GUARDAR CAMBIOS' : 'CREAR PROMOCIÓN' ?></button>
            </div>
        </form>
    </section>

    <?php if ($promotions === []): ?>
        <section class="empty-state">
            <h2>Todavía no hay promociones</h2>
            <p>Por ejemplo: "3 flats por C$1000" o "10% en la primera compra de tacones o plataformas".</p>
        </section>
    <?php else: ?>
        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Promoción</th>
                        <th>Vigencia</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promotions as $promotion): ?>
                        <?php $current = promotion_is_current($promotion, $today); ?>
                        <tr>
                            <td>
                                <?php if (!empty($promotion['image_path'])): ?>
                                    <img class="promotion-thumb" src="<?= e(APP_BASE_URL . '/' . ltrim((string) $promotion['image_path'], '/')) ?>" alt="">
                                <?php endif; ?>
                                <div class="product-name"><?= e($promotion['name']) ?></div>
                                <div class="product-code"><?= e(promotion_summary($promotion)) ?></div>
                            </td>
                            <td>
                                <?php if ($promotion['starts_on'] === null && $promotion['ends_on'] === null): ?>
                                    Sin fecha límite
                                <?php else: ?>
                                    <?= $promotion['starts_on'] !== null ? e(date('d/m/Y', strtotime((string) $promotion['starts_on']))) : 'Ya' ?>
                                    →
                                    <?= $promotion['ends_on'] !== null ? e(date('d/m/Y', strtotime((string) $promotion['ends_on']))) : 'sin fin' ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($current): ?>
                                    <span class="status-badge status-active">Vigente</span>
                                <?php elseif ((int) $promotion['is_active'] === 1): ?>
                                    <span class="status-badge status-inactive">Fuera de fecha</span>
                                <?php else: ?>
                                    <span class="status-badge status-inactive">Pausada</span>
                                <?php endif; ?>
                            </td>
                            <td class="num promotion-actions">
                                <a class="table-action-link" href="?edit=<?= (int) $promotion['id'] ?>">Editar</a>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $promotion['id'] ?>">
                                    <button class="table-action-link" type="submit">
                                        <?= (int) $promotion['is_active'] === 1 ? 'Pausar' : 'Activar' ?>
                                    </button>
                                </form>
                                <form method="post" onsubmit="return confirm('¿Eliminar esta promoción? Las ventas que ya la usaron conservan su descuento.');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $promotion['id'] ?>">
                                    <button class="table-action-link table-action-danger" type="submit">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <script>
        (() => {
            const form = document.querySelector("[data-promotion-form]");
            const type = form.querySelector("[data-promotion-type]");

            function sync() {
                form.querySelectorAll("[data-promotion-field]").forEach(field => {
                    field.hidden = field.dataset.promotionField !== type.value;
                });
            }

            type.addEventListener("change", sync);
            sync();
        })();
    </script>

<?php endif; ?>

<?php render_sales_layout_end(); ?>
