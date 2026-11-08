<?php declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/size-functions.php';
require_admin();
/*
|--------------------------------------------------------------------------
| Available Sizes
|--------------------------------------------------------------------------
*/
$availableSizes = store_sizes();
/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/
$error = '';
/*
|--------------------------------------------------------------------------
| Update Inventario
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {    /*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
    if (
        !verify_csrf_token(
            $_POST['csrf_token'] ?? null
        )
    ) {
        $error =
            'Token de seguridad inválido.';
    }
    /*
    |--------------------------------------------------------------------------
    | Stock Data
    |--------------------------------------------------------------------------
    */
    $stockData =
        $_POST['stock']
        ?? [];
    if (
        $error === '' &&
        !is_array($stockData)
    ) {
        $error =
            'Los datos del inventario no son válidos.';
    }
    /*
    |--------------------------------------------------------------------------
    | Validate Stock
    |--------------------------------------------------------------------------
    */
    $validatedStock = [];
    if ($error === '') {
        foreach (
            $stockData
            as
            $variantId =>
            $stock
        ) {
            if (
                !ctype_digit(
                    (string) $variantId
                )
            ) {
                $error =
                    'La variante del producto no es válida.';
                break;
            }
            if ($stock === '') {
                $stock = '0';
            }
            $validatedValue =
                filter_var(
                    $stock,
                    FILTER_VALIDATE_INT
                );
            if (
                $validatedValue === false ||
                $validatedValue < 0
            ) {
                $error =
                    'El stock debe ser cero o mayor.';
                break;
            }
            $validatedStock[
                (int) $variantId
            ] =
                (int) $validatedValue;
        }
    }
    /*
    |--------------------------------------------------------------------------
    | Save Inventario
    |--------------------------------------------------------------------------
    */
    if ($error === '') {
        try {
            $pdo->beginTransaction();
            $updateStatement =
                $pdo->prepare(
                    'UPDATE product_variants
                     SET stock = :stock
                     WHERE id = :id'
                );
            foreach (
                $validatedStock
                as
                $variantId =>
                $stock
            ) {
                $updateStatement->execute([
                    'stock' =>
                        $stock,
                    'id' =>
                        $variantId,
                ]);
            }
            $pdo->commit();
            header(
                'Location: ' .
                APP_BASE_URL .
                '/admin/inventory/?updated=1'
            );
            exit;
        } catch (
            Throwable $exception
        ) {
            if (
                $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }
            error_log(
                $exception->getMessage()
            );
            $error =
                'No se pudo actualizar el inventario.';
        }
    }
}
/*
|--------------------------------------------------------------------------
| Load Inventario
|--------------------------------------------------------------------------
*/
$inventoryStatement =
    $pdo->query(
        'SELECT
            pv.id AS variant_id,
            pv.product_id,
            pv.color_name,
            pv.color_hex,
            pv.size,
            pv.stock,            p.code,
            p.name,
            p.main_image,
            p.is_active,
            p.sort_order,            c.name AS category_name         FROM product_variants pv         INNER JOIN products p
            ON p.id = pv.product_id         INNER JOIN categories c
            ON c.id = p.category_id         ORDER BY
            p.sort_order ASC,
            p.name ASC,
            pv.color_name ASC,
            CAST(pv.size AS UNSIGNED) ASC'
    );
$inventoryRows =
    $inventoryStatement->fetchAll();
/*
|--------------------------------------------------------------------------
| Group Inventario
|--------------------------------------------------------------------------
*/
$products = [];
foreach (
    $inventoryRows
    as
    $row
) {
    $productId =
        (int) $row['product_id'];
    if (
        !isset(
        $products[$productId]
    )
    ) {
        $products[$productId] = [
            'id' =>
                $productId,
            'code' =>
                $row['code'],
            'name' =>
                $row['name'],
            'image' =>
                $row['main_image'],
            'category' =>
                $row['category_name'],
            'is_active' =>
                (int) $row['is_active'],
            'colors' =>
                [],
        ];
    }
    $colorName =
        trim(
            (string) 
            $row['color_name']
        );
    $colorKey =
        strtolower(
            $colorName
        );
    if (
        !isset(
        $products[
            $productId
        ]['colors'][
            $colorKey
        ]
    )
    ) {
        $products[
            $productId
        ]['colors'][
            $colorKey
        ] = [
            'name' =>
                $colorName,
            'hex' =>
                $row['color_hex']
                ?: '#d8c6b5',
            'sizes' =>
                [],
        ];
    }
    $products[
        $productId
    ]['colors'][
        $colorKey
    ]['sizes'][
        (string) $row['size']
    ] = [
        'variant_id' =>
            (int) 
            $row['variant_id'],
        'stock' =>
            (int) 
            $row['stock'],
    ];
}
/*
|--------------------------------------------------------------------------
| Filter Options (colors that exist in the inventory)
|--------------------------------------------------------------------------
*/
$filterColors = [];
foreach ($products as $product) {
    foreach ($product['colors'] as $colorKey => $color) {
        if ($colorKey === '' || isset($filterColors[$colorKey])) {
            continue;
        }
        $filterColors[$colorKey] = $color['name'];
    }
}
uasort($filterColors, static fn($a, $b) => strcasecmp($a, $b));
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Inventario | Administración <?= e(business_name()) ?>
    </title>
    <link href="<?= e(business_favicon_url()) ?>" rel="icon" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="../admin.css?v=<?= (int) @filemtime(__DIR__ . '/../admin.css') ?>">
</head>

<body>
    <header class="admin-header">
        <a href="<?= e(APP_BASE_URL) ?>/admin/"> <img class="admin-logo" src="<?= e(business_logo_url()) ?>"
                alt="<?= e(business_name()) ?>"> </a>
        <div class="admin-user">
            <span> <?= e(
                $_SESSION['admin_name']
            ) ?> </span>
            <form method="post" action="<?= e(APP_BASE_URL) ?>/admin/logout.php">
                <input type="hidden" name="csrf_token" value="<?= e(
                    csrf_token()
                ) ?>">
                <button class="logout-button" type="submit">
                    Cerrar sesión
                </button>
            </form>
        </div>
    </header>
    <div class="admin-layout">
        <aside class="sidebar">
            <p class="sidebar-title"> Administración
            </p>
            <?php require_once __DIR__ . '/../../includes/admin-nav.php'; ?>
            <?php render_admin_nav('inventory'); ?>
        </aside>
        <main class="admin-content">
            <div class="page-header">
                <div>
                    <span class="page-eyebrow">
                        STOCK
                    </span>
                    <h1 class="page-title">
                        Inventario
                    </h1>
                    <p class="page-description">
                        Administra el stock de productos por
                        color y talla.
                    </p>
                </div>
            </div> <?php if (
                isset(
                $_GET['updated']
            )
            ): ?>
                <div class="
                    admin-notice
                    admin-notice-success
                ">
                    Inventario actualizado correctamente.
                </div>
            <?php endif; ?> <?php if (
                   $error !== ''
               ): ?>
                <div class="
                    admin-notice
                    admin-notice-error
                "> <?= e($error) ?> </div>
            <?php endif; ?> <?php if (
                   count($products) === 0
               ): ?>
                <section class="empty-state">
                    <h2>
                        Aún no hay inventario
                    </h2>
                    <p>
                        Agrega productos y variantes
                        antes de administrar el stock.
                    </p>
                    <a class="admin-button" href="<?= e(APP_BASE_URL) ?>/admin/products/create.php">
                        AGREGAR PRODUCTO
                    </a>
                </section>
            <?php else: ?>
                <section class="inventory-filters" aria-label="Filtros de inventario">
                    <div class="inventory-filter-fields">
                        <div class="inventory-filter-field inventory-filter-name">
                            <label class="form-label" for="inventoryBuscar">
                                Modelo o código
                            </label>
                            <input class="form-input" id="inventoryBuscar" type="search"
                                placeholder="Ej. Flat Luna o ALT-001" autocomplete="off">
                        </div>
                        <div class="inventory-filter-field">
                            <label class="form-label" for="inventoryColor">
                                Color
                            </label>
                            <select class="form-input" id="inventoryColor">
                                <option value="">Todos los colores</option>
                                <?php foreach ($filterColors as $colorKey => $colorName): ?>
                                    <option value="<?= e((string) $colorKey) ?>"><?= e($colorName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="inventory-filter-field">
                            <label class="form-label" for="inventoryTalla">
                                Talla
                            </label>
                            <select class="form-input" id="inventoryTalla">
                                <option value="">Todas las tallas</option>
                                <?php foreach ($availableSizes as $size): ?>
                                    <option value="<?= e((string) $size) ?>"><?= e((string) $size) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <label class="inventory-filter-check">
                            <input type="checkbox" id="inventoryOnlyStock">
                            <span>Solo con stock</span>
                        </label>
                        <button class="inventory-filter-clear" type="button" id="inventoryClear" hidden>
                            Limpiar filtros
                        </button>
                    </div>
                    <div class="inventory-filter-result" aria-live="polite">
                        <div class="inventory-filter-total">
                            <span>Pares disponibles</span>
                            <strong id="inventoryFilterTotal">0</strong>
                        </div>
                        <p class="inventory-filter-detail" id="inventoryFilterDetail"></p>
                        <div class="inventory-filter-breakdown" id="inventoryFilterBreakdown"></div>
                    </div>
                </section>
                <section class="empty-state inventory-filter-empty" id="inventoryNoResults" hidden>
                    <h2>
                        No hay zapatos con esos filtros
                    </h2>
                    <p>
                        Prueba con otra talla, color o modelo.
                    </p>
                </section>
                <form method="post" id="inventoryForm">
                    <input type="hidden" name="csrf_token" value="<?= e(
                        csrf_token()
                    ) ?>">
                    <div class="inventory-products" id="inventoryProductos">
                        <?php foreach (
                            $products
                            as
                            $product
                        ): ?>
                            <?php $productTotalStock = 0;
                            foreach (
                                $product['colors']
                                as
                                $productColor
                            ) {
                                foreach (
                                    $productColor['sizes']
                                    as
                                    $sizeData
                                ) {
                                    $productTotalStock +=
                                        (int) 
                                        $sizeData['stock'];
                                }
                            } ?>
                            <section class="inventory-product-card" data-inventory-product data-name="<?= e($product['name']) ?>" data-search="<?= e(
                                strtolower(
                                    $product['code'] .
                                    ' ' .
                                    $product['name'] .
                                    ' ' .
                                    $product['category']
                                )
                            ) ?>">
                                <div class="inventory-product-header">
                                    <div class="inventory-product-main">
                                        <?php if (
                                            !empty(
                                            $product['image']
                                        )
                                        ): ?>
                                            <img class="inventory-product-image" src="../../<?= e(
                                                $product['image']
                                            ) ?>" alt="<?= e(
                                                 $product['name']
                                             ) ?>">
                                        <?php endif; ?>
                                        <div>
                                            <span class="inventory-product-code"> <?= e(
                                                $product['code']
                                            ) ?> </span>
                                            <h2> <?= e(
                                                $product['name']
                                            ) ?> </h2>
                                            <p> <?= e(
                                                $product['category']
                                            ) ?> </p>
                                        </div>
                                    </div>
                                    <div class="inventory-product-summary">
                                        <?php if (
                                            $product['is_active']
                                            === 1
                                        ): ?>
                                            <span class="
                                                status-badge
                                                status-active
                                            ">
                                                Activo
                                            </span>
                                        <?php else: ?>
                                            <span class="
                                                status-badge
                                                status-inactive
                                            ">
                                                Inactivo
                                            </span>
                                        <?php endif; ?>
                                        <div class="inventory-total inventory-total-match" data-product-match-box hidden> <span>
                                                Coinciden
                                            </span> <strong data-product-match>0</strong> </div>
                                        <div class="inventory-total"> <span>
                                                Stock total
                                            </span> <strong data-product-total> <?= $productTotalStock ?> </strong> </div>
                                    </div>
                                </div>
                                <div class="inventory-color-list">
                                    <?php foreach (
                                        $product['colors']
                                        as
                                        $color
                                    ): ?>
                                        <div class="inventory-color-row" data-color-row data-color="<?= e(strtolower(trim((string) $color['name']))) ?>" data-color-name="<?= e($color['name']) ?>">
                                            <div class="inventory-color-name">
                                                <span class="inventory-color-swatch" style="--inventory-color: <?= e(
                                                    $color['hex']
                                                ) ?>;"></span>
                                                <strong> <?= e(
                                                    $color['name']
                                                ) ?> </strong>
                                            </div>
                                            <div class="inventory-size-grid">
                                                <?php foreach (
                                                    $availableSizes
                                                    as
                                                    $size
                                                ): ?>
                                                    <?php $variant =
                                                        $color['sizes'][
                                                            $size
                                                        ]
                                                        ?? null; ?>
                                                    <div class="inventory-size-item" data-size-item data-size="<?= e((string) $size) ?>">
                                                        <label> <?= e($size) ?> </label> <?php if (
                                                               $variant
                                                               !== null
                                                           ): ?>
                                                            <input class="inventory-stock-input" type="number" min="0" step="1" name="stock[<?= (int) 
                                                                $variant[
                                                                    'variant_id'
                                                                ]
                                                                ?>]" value="<?= (int) 
                                                                $variant[
                                                                    'stock'
                                                                ]
                                                                ?>" data-stock-input>
                                                        <?php else: ?>
                                                            <span class="inventory-no-variant">
                                                                —
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                    <div class="inventory-save-bar">
                        <div>
                            <span class="inventory-save-label">
                                Inventario
                            </span>
                            <strong>
                                Guardar cambios de stock
                            </strong>
                        </div>
                        <button class="admin-button" type="submit">
                            GUARDAR INVENTARIO
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </main>
    </div>
    <script>document.addEventListener(
            "DOMContentLoaded",
            () => {        /*
        |--------------------------------------------------------------------------
        | Buscar
        |--------------------------------------------------------------------------
        */        const searchInput =
                    document.getElementById(
                        "inventoryBuscar"
                    );
                const products =
                    Array.from(
                        document.querySelectorAll(
                            "[data-inventory-product]"
                        )
                    );
const colorSelect =
                    document.getElementById("inventoryColor");
                const sizeSelect =
                    document.getElementById("inventoryTalla");
                const onlyStockInput =
                    document.getElementById("inventoryOnlyStock");
                const clearButton =
                    document.getElementById("inventoryClear");
                const totalOutput =
                    document.getElementById("inventoryFilterTotal");
                const detailOutput =
                    document.getElementById("inventoryFilterDetail");
                const breakdownOutput =
                    document.getElementById("inventoryFilterBreakdown");
                const noResults =
                    document.getElementById("inventoryNoResults");
                const filterStorageKey = "bmoInventoryFilters";

                function normalize(value) {
                    return String(value || "")
                        .toLowerCase()
                        .normalize("NFD")
                        .replace(/[\u0300-\u036f]/g, "")
                        .trim();
                }

                function stockOf(item) {
                    const input = item.querySelector("[data-stock-input]");
                    if (!input) {
                        return null;
                    }
                    const value = Number.parseInt(input.value, 10);
                    return Number.isFinite(value) && value > 0 ? value : 0;
                }

                function plural(count, one, many) {
                    return count + " " + (count === 1 ? one : many);
                }

                function saveFilters() {
                    try {
                        sessionStorage.setItem(filterStorageKey, JSON.stringify({
                            q: searchInput ? searchInput.value : "",
                            color: colorSelect ? colorSelect.value : "",
                            size: sizeSelect ? sizeSelect.value : "",
                            onlyStock: onlyStockInput ? onlyStockInput.checked : false
                        }));
                    } catch (error) {
                        // Storage blocked: filters just won't be remembered.
                    }
                }

                function restoreFilters() {
                    try {
                        const saved = JSON.parse(sessionStorage.getItem(filterStorageKey) || "null");
                        if (!saved) {
                            return;
                        }
                        if (searchInput) searchInput.value = saved.q || "";
                        if (colorSelect && Array.from(colorSelect.options).some(o => o.value === saved.color)) {
                            colorSelect.value = saved.color || "";
                        }
                        if (sizeSelect && Array.from(sizeSelect.options).some(o => o.value === saved.size)) {
                            sizeSelect.value = saved.size || "";
                        }
                        if (onlyStockInput) onlyStockInput.checked = Boolean(saved.onlyStock);
                    } catch (error) {
                        // Ignore broken or blocked storage.
                    }
                }

                function applyFilters() {
                    const query = normalize(searchInput ? searchInput.value : "");
                    const color = colorSelect ? colorSelect.value : "";
                    const size = sizeSelect ? sizeSelect.value : "";
                    const onlyStock = onlyStockInput ? onlyStockInput.checked : false;
                    const filtering = query !== "" || color !== "" || size !== "" || onlyStock;

                    let grandTotal = 0;
                    let modelCount = 0;
                    const bySize = {};
                    const byColor = {};

                    products.forEach(product => {
                        const matchesName =
                            query === "" ||
                            normalize(product.dataset.search).includes(query);
                        let productTotal = 0;
                        let visibleRows = 0;

                        product.querySelectorAll("[data-color-row]").forEach(row => {
                            const matchesColor =
                                color === "" || row.dataset.color === color;
                            let rowTotal = 0;
                            let rowHasVariant = false;

                            row.querySelectorAll("[data-size-item]").forEach(item => {
                                const matchesSize =
                                    size === "" || item.dataset.size === size;
                                const stock = stockOf(item);
                                item.classList.toggle(
                                    "inventory-size-dimmed",
                                    size !== "" && !matchesSize
                                );
                                item.classList.toggle(
                                    "inventory-size-match",
                                    size !== "" && matchesSize
                                );
                                if (!matchesSize || stock === null) {
                                    return;
                                }
                                rowHasVariant = true;
                                rowTotal += stock;
                                if (matchesName && matchesColor) {
                                    const sizeKey = item.dataset.size;
                                    bySize[sizeKey] = (bySize[sizeKey] || 0) + stock;
                                    const colorName = row.dataset.colorName || "Sin color";
                                    byColor[colorName] = (byColor[colorName] || 0) + stock;
                                }
                            });

                            const rowVisible =
                                matchesName &&
                                matchesColor &&
                                rowHasVariant &&
                                (!onlyStock || rowTotal > 0);
                            row.hidden = !rowVisible;
                            if (rowVisible) {
                                visibleRows++;
                                productTotal += rowTotal;
                            }
                        });

                        const productVisible = visibleRows > 0;
                        product.hidden = !productVisible;

                        const matchBox = product.querySelector("[data-product-match-box]");
                        const matchOutput = product.querySelector("[data-product-match]");
                        if (matchBox && matchOutput) {
                            matchBox.hidden = !filtering || !productVisible;
                            matchOutput.textContent = String(productTotal);
                        }

                        if (productVisible) {
                            modelCount++;
                            grandTotal += productTotal;
                        }
                    });

                    if (totalOutput) {
                        totalOutput.textContent = String(grandTotal);
                    }

                    if (detailOutput) {
                        const parts = [];
                        if (query !== "") parts.push("modelo «" + (searchInput.value.trim()) + "»");
                        if (color !== "") parts.push("color " + colorSelect.options[colorSelect.selectedIndex].text);
                        if (size !== "") parts.push("talla " + size);
                        const scope = parts.length > 0 ? parts.join(", ") : "todo el inventario";
                        detailOutput.textContent =
                            plural(modelCount, "modelo", "modelos") + " · " + scope;
                    }

                    if (breakdownOutput) {
                        breakdownOutput.innerHTML = "";
                        let entries = [];
                        if (size === "") {
                            entries = Object.keys(bySize)
                                .sort((a, b) => Number.parseFloat(a) - Number.parseFloat(b))
                                .map(key => ["T" + key, bySize[key]]);
                        } else if (color === "") {
                            entries = Object.keys(byColor)
                                .sort((a, b) => a.localeCompare(b, "es"))
                                .map(key => [key, byColor[key]]);
                        }
                        entries.forEach(([label, value]) => {
                            if (onlyStock && value <= 0) {
                                return;
                            }
                            const chip = document.createElement("span");
                            chip.className = "inventory-filter-chip" + (value <= 0 ? " is-empty" : "");
                            const name = document.createElement("small");
                            name.textContent = label;
                            const amount = document.createElement("b");
                            amount.textContent = String(value);
                            chip.append(name, amount);
                            breakdownOutput.append(chip);
                        });
                        breakdownOutput.hidden = breakdownOutput.childElementCount === 0;
                    }

                    if (noResults) {
                        noResults.hidden = modelCount > 0;
                    }
                    if (clearButton) {
                        clearButton.hidden = !filtering;
                    }
                    saveFilters();
                }

                [searchInput, colorSelect, sizeSelect, onlyStockInput].forEach(control => {
                    if (control) {
                        control.addEventListener("input", applyFilters);
                        control.addEventListener("change", applyFilters);
                    }
                });

                if (clearButton) {
                    clearButton.addEventListener("click", () => {
                        if (searchInput) searchInput.value = "";
                        if (colorSelect) colorSelect.value = "";
                        if (sizeSelect) sizeSelect.value = "";
                        if (onlyStockInput) onlyStockInput.checked = false;
                        applyFilters();
                    });
                }

                // Stock edited by hand: refresh the filtered totals too.
                document.addEventListener("input", event => {
                    if (event.target.closest && event.target.closest("[data-stock-input]")) {
                        applyFilters();
                    }
                });

                restoreFilters();
                applyFilters();
        /*
        |--------------------------------------------------------------------------
        | Live Product Totals
        |--------------------------------------------------------------------------
        */        products.forEach(
                    product => {
                        const inputs =
                            Array.from(
                                product.querySelectorAll(
                                    "[data-stock-input]"
                                )
                            );
                        const totalElement =
                            product.querySelector(
                                "[data-product-total]"
                            );
                        function updateTotal() {
                            let total = 0;
                            inputs.forEach(
                                input => {
                                    const value =
                                        Number.parseInt(
                                            input.value,
                                            10
                                        );
                                    if (
                                        Number.isFinite(
                                            value
                                        ) &&
                                        value > 0
                                    ) {
                                        total += value;
                                    }
                                }
                            );
                            if (totalElement) {
                                totalElement.textContent =
                                    String(total);
                            }
                        }
                        inputs.forEach(
                            input => {
                                input.addEventListener(
                                    "input",
                                    updateTotal
                                );
                            }
                        );
                    }
                );        /*
        |--------------------------------------------------------------------------
        | Prevent Negative Values
        |--------------------------------------------------------------------------
        */        document.addEventListener(
                    "input",
                    event => {
                        const input =
                            event.target.closest(
                                "[data-stock-input]"
                            );
                        if (!input) {
                            return;
                        }
                        if (
                            Number(input.value) < 0
                        ) { input.value = "0"; }
                    }
                );
            }
        );</script>
</body>

</html>