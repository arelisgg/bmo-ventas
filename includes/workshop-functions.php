<?php

declare(strict_types=1);

require_once __DIR__ . '/sales-functions.php';


/*
|--------------------------------------------------------------------------
| Workshop Orders
|--------------------------------------------------------------------------
|
| Orders handed to the workshop: each model with its photo and the
| number of pairs per color and size. Needs 006_workshop_orders.sql.
|
*/

function workshop_tables_exist(PDO $pdo): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $pdo->query('SELECT 1 FROM workshop_orders LIMIT 1');
        $pdo->query('SELECT 1 FROM workshop_order_models LIMIT 1');
        $pdo->query('SELECT 1 FROM workshop_order_lines LIMIT 1');
        $exists = true;
    } catch (PDOException) {
        $exists = false;
    }

    return $exists;
}

function workshop_statuses(): array
{
    return [
        'pending' => 'Pendiente',
        'in_progress' => 'En el taller',
        'done' => 'Terminado',
        'cancelled' => 'Anulado',
    ];
}

function workshop_default_sizes(): array
{
    return ['35', '36', '37', '38', '39', '40'];
}

function workshop_order_number(int $orderId): string
{
    return 'PT-' . str_pad((string) $orderId, 5, '0', STR_PAD_LEFT);
}

function workshop_pairs_label(int $pairs): string
{
    return $pairs . ($pairs === 1 ? ' par' : ' pares');
}

function render_workshop_migration_notice(): void
{
    ?>
    <section class="empty-state">
        <h2>Falta crear las tablas de pedidos al taller</h2>
        <p>
            En <a href="<?= e(APP_BASE_URL) ?>/admin/settings/">Configuración</a> pulsa
            <strong>ACTUALIZAR BASE DE DATOS</strong> (solo el dueño puede hacerlo). Luego recarga esta página.
        </p>
    </section>
<?php
}


/*
|--------------------------------------------------------------------------
| Catalog Products For The Model Picker
|--------------------------------------------------------------------------
*/

function workshop_products(PDO $pdo): array
{
    $products = $pdo->query(
        'SELECT
            p.id,
            p.code,
            p.name,
            p.main_image,
            GROUP_CONCAT(DISTINCT v.color_name ORDER BY v.color_name SEPARATOR "|") AS colors
         FROM products p
         LEFT JOIN product_variants v ON v.product_id = p.id
         GROUP BY p.id, p.code, p.name, p.main_image
         ORDER BY p.name ASC, p.code ASC'
    )->fetchAll();

    $byId = [];

    foreach ($products as $product) {
        $product['colors'] = array_values(array_filter(explode('|', (string) $product['colors'])));
        $byId[(int) $product['id']] = $product;
    }

    return $byId;
}


/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
|
| A model in the form:
| ['product_id', 'name', 'code', 'image_path', 'notes',
|  'colors' => [['name' => 'Camel', 'qty' => ['36' => '2']]]]
|
*/

function workshop_empty_model(): array
{
    return [
        'product_id' => '',
        'name' => '',
        'code' => '',
        'image_path' => '',
        'notes' => '',
        'colors' => [['name' => '', 'qty' => []]],
    ];
}

function workshop_form_from_sale(PDO $pdo, int $saleId): ?array
{
    $sale = $pdo->prepare('SELECT * FROM sales WHERE id = :id LIMIT 1');
    $sale->execute(['id' => $saleId]);
    $sale = $sale->fetch();

    if (!$sale) {
        return null;
    }

    $items = $pdo->prepare(
        'SELECT si.*, p.id AS existing_product_id
         FROM sale_items si
         LEFT JOIN products p ON p.id = si.product_id
         WHERE si.sale_id = :sale_id
         ORDER BY si.id ASC'
    );
    $items->execute(['sale_id' => $saleId]);

    $models = [];

    foreach ($items->fetchAll() as $item) {
        $productId = (string) ($item['existing_product_id'] ?? '');
        $key = $productId !== '' ? 'p' . $productId : 'n' . $item['product_code'] . '|' . $item['product_name'];

        if (!isset($models[$key])) {
            $models[$key] = workshop_empty_model();
            $models[$key]['product_id'] = $productId;
            $models[$key]['name'] = (string) $item['product_name'];
            $models[$key]['code'] = (string) $item['product_code'];
            $models[$key]['image_path'] = (string) ($item['image_path'] ?? '');
            $models[$key]['colors'] = [];
        }

        $color = (string) $item['color_name'];
        $colorIndex = null;

        foreach ($models[$key]['colors'] as $index => $existing) {
            if (mb_strtolower($existing['name']) === mb_strtolower($color)) {
                $colorIndex = $index;
            }
        }

        if ($colorIndex === null) {
            $models[$key]['colors'][] = ['name' => $color, 'qty' => []];
            $colorIndex = array_key_last($models[$key]['colors']);
        }

        $size = (string) $item['size'] !== '' ? (string) $item['size'] : 'Sin talla';
        $current = (int) ($models[$key]['colors'][$colorIndex]['qty'][$size] ?? 0);
        $models[$key]['colors'][$colorIndex]['qty'][$size] = (string) ($current + (int) $item['quantity']);
    }

    return [
        'reference' => (string) ($sale['customer_name'] ?? ''),
        'sale_id' => (string) $saleId,
        'sale_number' => $sale['sale_number'] ?: sale_number_for_id($saleId),
        'due_date' => '',
        'notes' => '',
        'models' => $models !== [] ? array_values($models) : [workshop_empty_model()],
    ];
}

function workshop_models_from_post(mixed $posted): array
{
    $models = [];

    foreach (is_array($posted) ? $posted : [] as $key => $model) {
        if (!is_array($model)) {
            continue;
        }

        $colors = [];

        foreach (is_array($model['colors'] ?? null) ? $model['colors'] : [] as $color) {
            if (!is_array($color)) {
                continue;
            }

            $qty = [];

            foreach (is_array($color['qty'] ?? null) ? $color['qty'] : [] as $size => $value) {
                $qty[(string) $size] = is_string($value) ? trim($value) : '';
            }

            $colors[] = [
                'name' => is_string($color['name'] ?? null) ? trim($color['name']) : '',
                'qty' => $qty,
            ];
        }

        $text = static fn (string $field): string =>
            is_string($model[$field] ?? null) ? trim($model[$field]) : '';

        $models[(string) $key] = [
            'product_id' => $text('product_id'),
            'name' => $text('name'),
            'code' => $text('code'),
            'image_path' => $text('image_path'),
            'notes' => $text('notes'),
            'colors' => $colors !== [] ? $colors : [['name' => '', 'qty' => []]],
        ];
    }

    return $models;
}

function workshop_sizes_for(array $models): array
{
    $sizes = workshop_default_sizes();

    foreach ($models as $model) {
        foreach ($model['colors'] as $color) {
            foreach ($color['qty'] as $size => $value) {
                if ((int) $value > 0 && !in_array((string) $size, $sizes, true)) {
                    $sizes[] = (string) $size;
                }
            }
        }
    }

    usort($sizes, static fn (string $a, string $b): int => strnatcmp($a, $b));

    return $sizes;
}


/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
|
| Returns [models ready to save, error]. Each saved model:
| ['form_key', 'product_id', 'name', 'code', 'image_path', 'notes',
|  'lines' => [['color', 'size', 'quantity']]]
|
*/

function validate_workshop_models(array $models, array $products): array
{
    $clean = [];
    $number = 0;

    foreach ($models as $key => $model) {
        $productId = (int) $model['product_id'];
        $product = $products[$productId] ?? null;
        $lines = [];
        $hasQuantity = false;

        foreach ($model['colors'] as $color) {
            foreach ($color['qty'] as $size => $value) {
                if ($value === '' || $value === '0') {
                    continue;
                }

                if (!ctype_digit($value) || (int) $value > 9999) {
                    return [[], 'Las cantidades deben ser números enteros.'];
                }

                $hasQuantity = true;

                $lines[] = [
                    'color' => mb_substr($color['name'], 0, 100),
                    'size' => mb_substr((string) $size, 0, 20),
                    'quantity' => (int) $value,
                ];
            }
        }

        $isEmpty = $productId === 0 && $model['name'] === '' && !$hasQuantity;

        if ($isEmpty) {
            continue;
        }

        $number++;

        if ($productId !== 0 && $product === null) {
            return [[], 'El modelo ' . $number . ' ya no existe en el catálogo. Elígelo de nuevo.'];
        }

        if ($product === null && $model['name'] === '') {
            return [[], 'Elige el modelo ' . $number . ' del catálogo o escribe su nombre.'];
        }

        if (!$hasQuantity) {
            return [[], 'El modelo ' . $number . ' no tiene ninguna cantidad.'];
        }

        $imagePath = ltrim($model['image_path'], '/');

        if (
            $imagePath !== '' &&
            (!preg_match('#^uploads/(sales|workshop)/[a-f0-9]+\.(jpg|png|webp)$#', $imagePath) ||
                !is_file(dirname(__DIR__) . '/' . $imagePath))
        ) {
            $imagePath = '';
        }

        $clean[] = [
            'form_key' => (string) $key,
            'product_id' => $product !== null ? $productId : null,
            'name' => mb_substr($product['name'] ?? $model['name'], 0, 180),
            'code' => mb_substr($product['code'] ?? $model['code'], 0, 100),
            'image_path' => $imagePath,
            'notes' => mb_substr($model['notes'], 0, 2000),
            'lines' => $lines,
        ];
    }

    if ($clean === []) {
        return [[], 'Agrega al menos un modelo con cantidades.'];
    }

    return [$clean, ''];
}


/*
|--------------------------------------------------------------------------
| Model Photo
|--------------------------------------------------------------------------
|
| Uses the sale photo upload checks, then moves the file to
| uploads/workshop/ so deleting an order never touches sale photos.
|
*/

function workshop_uploaded_file(string $formKey): array
{
    $files = $_FILES['models'] ?? null;

    if (!is_array($files) || !isset($files['error'][$formKey]['photo'])) {
        return ['error' => UPLOAD_ERR_NO_FILE];
    }

    $file = [];

    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $field) {
        $file[$field] = $files[$field][$formKey]['photo'] ?? null;
    }

    return $file;
}

function upload_workshop_model_image(array $file): ?string
{
    $salePath = upload_sale_item_image($file);

    if ($salePath === null) {
        return null;
    }

    $root = dirname(__DIR__);
    $directory = $root . '/uploads/workshop';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create uploads/workshop.');
    }

    $path = 'uploads/workshop/' . basename($salePath);

    if (!rename($root . '/' . $salePath, $root . '/' . $path)) {
        throw new RuntimeException('Unable to move workshop image.');
    }

    return $path;
}

function delete_workshop_model_image(?string $imagePath): void
{
    $imagePath = ltrim((string) $imagePath, '/');

    if (!str_starts_with($imagePath, 'uploads/workshop/')) {
        return;
    }

    $absolutePath = dirname(__DIR__) . '/' . $imagePath;

    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}


/*
|--------------------------------------------------------------------------
| Document Helpers
|--------------------------------------------------------------------------
*/

function workshop_load_order(PDO $pdo, int $orderId): ?array
{
    $order = $pdo->prepare(
        'SELECT o.*, s.sale_number
         FROM workshop_orders o
         LEFT JOIN sales s ON s.id = o.sale_id
         WHERE o.id = :id
         LIMIT 1'
    );
    $order->execute(['id' => $orderId]);
    $order = $order->fetch();

    if (!$order) {
        return null;
    }

    $models = $pdo->prepare(
        'SELECT m.*, COALESCE(NULLIF(m.image_path, ""), p.main_image) AS photo
         FROM workshop_order_models m
         LEFT JOIN products p ON p.id = m.product_id
         WHERE m.order_id = :order_id
         ORDER BY m.sort_order ASC, m.id ASC'
    );
    $models->execute(['order_id' => $orderId]);
    $order['models'] = $models->fetchAll();

    $lines = $pdo->prepare(
        'SELECT l.*
         FROM workshop_order_lines l
         INNER JOIN workshop_order_models m ON m.id = l.model_id
         WHERE m.order_id = :order_id
         ORDER BY l.id ASC'
    );
    $lines->execute(['order_id' => $orderId]);

    $byModel = [];

    foreach ($lines->fetchAll() as $line) {
        $byModel[(int) $line['model_id']][] = $line;
    }

    $order['total_pairs'] = 0;

    foreach ($order['models'] as &$model) {
        $model['lines'] = $byModel[(int) $model['id']] ?? [];
        $model['pairs'] = array_sum(array_map(static fn (array $line): int => (int) $line['quantity'], $model['lines']));
        $order['total_pairs'] += $model['pairs'];
    }
    unset($model);

    return $order;
}

/*
| Turns lines into ['sizes' => [...], 'rows' => [color => [size => qty]]]
| keeping only the sizes this model actually uses.
*/
function workshop_line_matrix(array $lines): array
{
    $sizes = [];
    $rows = [];

    foreach ($lines as $line) {
        $size = (string) $line['size'];
        $color = (string) $line['color_name'];

        $sizes[$size] = true;
        $rows[$color][$size] = ($rows[$color][$size] ?? 0) + (int) $line['quantity'];
    }

    $sizes = array_keys($sizes);
    usort($sizes, static fn (string|int $a, string|int $b): int => strnatcmp((string) $a, (string) $b));

    return ['sizes' => array_map('strval', $sizes), 'rows' => $rows];
}


/*
|--------------------------------------------------------------------------
| Model Block (form)
|--------------------------------------------------------------------------
|
| Also rendered with index "__MODEL__" as the template the
| "+ AGREGAR MODELO" button clones.
|
*/

function render_workshop_model_block(string $index, array $model, array $products, array $sizes): void
{
    $base = APP_BASE_URL;
    $productId = (int) $model['product_id'];
    $product = $products[$productId] ?? null;
    $preview = $model['image_path'] !== '' ? $model['image_path'] : (string) ($product['main_image'] ?? '');
    $name = 'models[' . $index . ']';
    ?>
    <div class="color-block workshop-model" data-model data-index="<?= e($index) ?>">
        <div class="color-block-header">
            <p class="color-block-title">Modelo <span data-model-number></span></p>
            <button class="remove-color-button" type="button" data-remove-model>Quitar modelo</button>
        </div>

        <div class="workshop-model-grid">
            <div class="workshop-model-photo">
                <img src="<?= $preview !== '' ? e($base . '/' . ltrim($preview, '/')) : '' ?>" alt=""
                    data-model-preview <?= $preview === '' ? 'hidden' : '' ?>>
                <span class="workshop-model-photo-empty" data-model-preview-empty <?= $preview !== '' ? 'hidden' : '' ?>>
                    Sin foto
                </span>
            </div>

            <div class="workshop-model-fields">
                <div class="form-group">
                    <label class="form-label">Modelo del catálogo</label>
                    <select class="form-select" name="<?= e($name) ?>[product_id]" data-model-product>
                        <option value="">— Modelo nuevo (no está en el catálogo) —</option>
                        <?php foreach ($products as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"
                                data-image="<?= e((string) $option['main_image'] !== '' ? $base . '/' . ltrim((string) $option['main_image'], '/') : '') ?>"
                                data-colors="<?= e(implode('|', $option['colors'])) ?>"
                                <?= (int) $option['id'] === $productId ? 'selected' : '' ?>>
                                <?= e($option['name'] . ' · ' . $option['code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="workshop-new-model" data-model-new <?= $product !== null ? 'hidden' : '' ?>>
                    <div class="form-group">
                        <label class="form-label">Nombre del modelo</label>
                        <input class="form-input" type="text" name="<?= e($name) ?>[name]" maxlength="180"
                            value="<?= e($model['name']) ?>" placeholder="Ej.: Sandalia trenzada">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Código (opcional)</label>
                        <input class="form-input" type="text" name="<?= e($name) ?>[code]" maxlength="100"
                            value="<?= e($model['code']) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Foto <?= $product !== null ? '(opcional, cambia la del catálogo)' : '' ?></label>
                    <input class="form-input" type="file" name="<?= e($name) ?>[photo]"
                        accept="image/jpeg,image/png,image/webp" data-model-photo>
                    <input type="hidden" name="<?= e($name) ?>[image_path]" value="<?= e($model['image_path']) ?>"
                        data-model-image-path>
                </div>

                <div class="form-group">
                    <label class="form-label">Detalles o comentarios (opcional)</label>
                    <textarea class="form-textarea" name="<?= e($name) ?>[notes]" rows="3" maxlength="2000"
                        placeholder="Ej.: tacón de 7 cm, suela beige, hebilla dorada"><?= e($model['notes']) ?></textarea>
                </div>
            </div>
        </div>

        <p class="stock-title">Pares por color y talla</p>

        <datalist id="workshop-colors-<?= e($index) ?>" data-color-list>
            <?php foreach ($product['colors'] ?? [] as $colorOption): ?>
                <option value="<?= e($colorOption) ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <div class="workshop-color-rows" data-color-rows>
            <?php foreach ($model['colors'] as $colorIndex => $color): ?>
                <?php render_workshop_color_row($index, (string) $colorIndex, $color, $sizes); ?>
            <?php endforeach; ?>
        </div>

        <div class="workshop-model-footer">
            <button class="admin-button admin-button-outline" type="button" data-add-color>+ AGREGAR COLOR</button>
            <span class="workshop-model-total">Total del modelo: <strong data-model-total>0</strong> pares</span>
        </div>
    </div>
<?php
}

function render_workshop_color_row(string $modelIndex, string $colorIndex, array $color, array $sizes): void
{
    $name = 'models[' . $modelIndex . '][colors][' . $colorIndex . ']';
    ?>
    <div class="workshop-color-row" data-color-row>
        <div class="workshop-color-name">
            <label>Color</label>
            <input class="form-input" type="text" name="<?= e($name) ?>[name]" maxlength="100"
                value="<?= e($color['name']) ?>" placeholder="Ej.: Camel" list="workshop-colors-<?= e($modelIndex) ?>" data-color-name>
        </div>
        <div class="stock-grid workshop-size-grid" style="--sizes: <?= count($sizes) ?>">
            <?php foreach ($sizes as $size): ?>
                <div class="stock-item">
                    <label>Talla <?= e($size) ?></label>
                    <input type="number" min="0" max="9999" step="1" inputmode="numeric"
                        name="<?= e($name) ?>[qty][<?= e($size) ?>]"
                        value="<?= e((string) ($color['qty'][$size] ?? '')) ?>" placeholder="0" data-qty>
                </div>
            <?php endforeach; ?>
        </div>
        <button class="remove-color-button workshop-remove-color" type="button" data-remove-color
            aria-label="Quitar color">Quitar</button>
    </div>
<?php
}
