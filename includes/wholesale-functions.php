<?php

declare(strict_types=1);

require_once __DIR__ . '/license-functions.php';

/*
|--------------------------------------------------------------------------
| Wholesale Products
|--------------------------------------------------------------------------
|
| Optional wholesale price and minimum quantity per product. Columns come
| from database/migrations/004_wholesale_products.sql; until it is
| imported every helper here does nothing, so the site keeps working.
|
*/

function wholesale_columns_exist(PDO $pdo): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    // Mayorista is a paid module (includes/license-functions.php).
    if (!license_has_module('wholesale')) {
        return $exists = false;
    }

    try {
        $pdo->query(
            'SELECT is_wholesale, wholesale_price, wholesale_min_qty
             FROM products
             LIMIT 1'
        );

        $exists = true;
    } catch (PDOException $exception) {
        $exists = false;
    }

    return $exists;
}

function wholesale_select_columns(PDO $pdo, string $alias = 'p'): string
{
    if (!wholesale_columns_exist($pdo)) {
        return '';
    }

    return $alias . '.is_wholesale, ' .
        $alias . '.wholesale_price, ' .
        $alias . '.wholesale_min_qty, ';
}

function wholesale_form_values(bool $isPost, ?array $product = null): array
{
    if ($isPost) {
        return [
            'is_wholesale' => ($_POST['is_wholesale'] ?? '0') === '1',
            'wholesale_price' => trim((string) ($_POST['wholesale_price'] ?? '')),
            'wholesale_min_qty' => trim((string) ($_POST['wholesale_min_qty'] ?? '')),
        ];
    }

    return [
        'is_wholesale' => (int) ($product['is_wholesale'] ?? 0) === 1,
        'wholesale_price' => (string) ($product['wholesale_price'] ?? ''),
        'wholesale_min_qty' => (string) ($product['wholesale_min_qty'] ?? ''),
    ];
}

/**
 * Returns [values for the database, error message].
 */
function validate_wholesale_form(array $form): array
{
    if (!$form['is_wholesale']) {
        return [
            [
                'is_wholesale' => 0,
                'wholesale_price' => null,
                'wholesale_min_qty' => null,
            ],
            '',
        ];
    }

    $price = str_replace(',', '.', $form['wholesale_price']);

    if ($price === '' || !is_numeric($price) || (float) $price < 0) {
        return [null, 'Ingresa un precio mayorista válido.'];
    }

    $minimum = filter_var(
        $form['wholesale_min_qty'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($minimum === false) {
        return [null, 'Ingresa la cantidad mínima de pares para la compra mayorista.'];
    }

    return [
        [
            'is_wholesale' => 1,
            'wholesale_price' => number_format((float) $price, 2, '.', ''),
            'wholesale_min_qty' => $minimum,
        ],
        '',
    ];
}

function save_product_wholesale(PDO $pdo, int $productId, ?array $values): void
{
    if ($values === null || !wholesale_columns_exist($pdo)) {
        return;
    }

    $pdo->prepare(
        'UPDATE products
         SET
            is_wholesale = :is_wholesale,
            wholesale_price = :wholesale_price,
            wholesale_min_qty = :wholesale_min_qty
         WHERE id = :id'
    )->execute($values + ['id' => $productId]);
}

/**
 * "Mayorista: C$900.00 desde 6 pares", or '' when not wholesale.
 */
function wholesale_label(array $product): string
{
    if ((int) ($product['is_wholesale'] ?? 0) !== 1 || $product['wholesale_price'] === null) {
        return '';
    }

    $label = 'Mayorista: ' . currency_symbol() . number_format((float) $product['wholesale_price'], 2);
    $minimum = (int) ($product['wholesale_min_qty'] ?? 0);

    if ($minimum > 1) {
        $label .= ' desde ' . $minimum . ' pares';
    }

    return $label;
}

function render_wholesale_fields(PDO $pdo, array $form): void
{
    if (!wholesale_columns_exist($pdo)) {
        ?>
        <div class="form-group form-group-full">
            <p class="form-hint">
                Para marcar productos mayoristas, el dueño debe pulsar
                <strong>ACTUALIZAR BASE DE DATOS</strong> en Configuración.
            </p>
        </div>
        <?php
        return;
    }
    ?>
    <div class="form-group form-group-full" data-wholesale>
        <label class="form-label" for="is_wholesale">
            ¿Producto mayorista?
        </label>

        <select class="form-select" id="is_wholesale" name="is_wholesale" data-wholesale-toggle>
            <option value="0" <?= $form['is_wholesale'] ? '' : 'selected' ?>>No</option>
            <option value="1" <?= $form['is_wholesale'] ? 'selected' : '' ?>>Sí, también se vende al por mayor</option>
        </select>

        <div class="wholesale-fields" data-wholesale-fields <?= $form['is_wholesale'] ? '' : 'hidden' ?>>
            <div>
                <label class="form-label" for="wholesale_price">
                    Precio mayorista
                </label>
                <input class="form-input" id="wholesale_price" name="wholesale_price" type="number"
                    min="0" step="0.01" value="<?= e($form['wholesale_price']) ?>">
            </div>
            <div>
                <label class="form-label" for="wholesale_min_qty">
                    Cantidad mínima (pares)
                </label>
                <input class="form-input" id="wholesale_min_qty" name="wholesale_min_qty" type="number"
                    min="1" step="1" value="<?= e($form['wholesale_min_qty']) ?>">
            </div>
        </div>

        <p class="form-hint">
            Se muestra en el catálogo y se usa al registrar ventas por el canal Mayorista.
        </p>
    </div>

    <script>
        (() => {
            const wrapper = document.currentScript.previousElementSibling;
            const toggle = wrapper.querySelector("[data-wholesale-toggle]");
            const fields = wrapper.querySelector("[data-wholesale-fields]");

            toggle.addEventListener("change", () => {
                fields.hidden = toggle.value !== "1";
            });
        })();
    </script>
    <?php
}
