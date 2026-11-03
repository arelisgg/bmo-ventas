<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/size-functions.php';
require_once __DIR__ . '/../../includes/product-functions.php';
require_once __DIR__ . '/../../includes/wholesale-functions.php';
require_once __DIR__ . '/../../includes/finance-functions.php';
require_admin();
/*
|--------------------------------------------------------------------------
| Available Sizes
|--------------------------------------------------------------------------
*/
$availableSizes = store_sizes();
/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/
$categoryStatement = $pdo->query(
    'SELECT
        id,
        name,
        slug,
        is_coming_soon
     FROM categories
     WHERE is_active = 1
     ORDER BY
        sort_order ASC,
        name ASC'
);
$categories =
    $categoryStatement->fetchAll();
/*
|--------------------------------------------------------------------------
| Form Defaults
|--------------------------------------------------------------------------
*/
$error = '';
$categoryId =
    (string) (
        $_POST['category_id']
        ?? ''
    );
$code =
    trim(
        (string) (
            $_POST['code']
            ?? ''
        )
    );
$name =
    trim(
        (string) (
            $_POST['name']
            ?? ''
        )
    );
$description =
    trim(
        (string) (
            $_POST['description']
            ?? ''
        )
    );
$price =
    trim(
        (string) (
            $_POST['price']
            ?? ''
        )
    );
$isActive =
    isset(
    $_POST['is_active']
);
$isFeatured =
    isset(
    $_POST['is_featured']
);
$isNew =
    isset(
    $_POST['is_new']
);
$wholesaleForm = wholesale_form_values($_SERVER['REQUEST_METHOD'] === 'POST');
$wholesaleValues = null;
$costForm = product_cost_form_value($_SERVER['REQUEST_METHOD'] === 'POST');
$costValues = null;
/*
|--------------------------------------------------------------------------
| Color / Variant Defaults
|--------------------------------------------------------------------------
*/
$formColors =
    $_POST['colors']
    ?? [
        [
            'name' => '',
            'hex' => '#d8c6b5',
            'stock' => array_fill_keys(store_sizes(), '0'),
        ],
    ];
if (!is_array($formColors)) {
    $formColors = [];
}
/*
|--------------------------------------------------------------------------
| Create Product
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {
    $csrfToken =
        $_POST['csrf_token']
        ?? null;
    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */
    if (
        !verify_csrf_token(
            $csrfToken
        )
    ) {
        $error =
            'Token de seguridad no válido.';
    }
    /*
    |--------------------------------------------------------------------------
    | Basic Fields
    |--------------------------------------------------------------------------
    */ elseif (
        $categoryId === '' ||
        !ctype_digit(
            $categoryId
        )
    ) {
        $error =
            'Selecciona una categoría válida.';
    } elseif ($code === '') {
        $error =
            'El código del producto es obligatorio.';
    } elseif ($name === '') {
        $error =
            'El nombre del producto es obligatorio.';
    } else {
        /*
        |--------------------------------------------------------------------------
        | Validate Category
        |--------------------------------------------------------------------------
        */
        $categoryCheck =
            $pdo->prepare(
                'SELECT id
                 FROM categories
                 WHERE
                    id = :id
                    AND is_active = 1
                 LIMIT 1'
            );
        $categoryCheck->execute([
            'id' =>
                (int) $categoryId,
        ]);
        $validCategoría =
            $categoryCheck->fetch();
        if (!$validCategoría) {
            $error =
                'La categoría seleccionada no existe.';
        }
        /*
        |--------------------------------------------------------------------------
        | Duplicate Product Code
        |--------------------------------------------------------------------------
        */
        if ($error === '') {
            $codeCheck =
                $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM products
                     WHERE code = :code'
                );
            $codeCheck->execute([
                'code' => $code,
            ]);
            if (
                (int) 
                $codeCheck->fetchColumn()
                > 0
            ) {
                $error =
                    'Ya existe un producto con este código.';
            }
        }
        /*
        |--------------------------------------------------------------------------
        | Price
        |--------------------------------------------------------------------------
        */
        $priceValue = null;
        if (
            $error === '' &&
            $price !== ''
        ) {
            $normalizedPrecio =
                str_replace(
                    ',',
                    '.',
                    $price
                );
            if (
                !is_numeric(
                    $normalizedPrecio
                ) ||
                (float) 
                $normalizedPrecio
                < 0
            ) {
                $error =
                    'Ingresa un precio válido.';
            } else {
                $priceValue =
                    number_format(
                        (float) 
                        $normalizedPrecio,
                        2,
                        '.',
                        ''
                    );
            }
        }
        if ($error === '') {
            [$wholesaleValues, $error] = validate_wholesale_form($wholesaleForm);
        }
        if ($error === '') {
            [$costValues, $error] = validate_product_cost($costForm);
        }
        /*
        |--------------------------------------------------------------------------
        | Validate Colors + Stock
        |--------------------------------------------------------------------------
        */
        $variants = [];
        $seenColors = [];
        if ($error === '') {
            if (
                count($formColors)
                === 0
            ) {
                $error =
                    'Agrega al menos un color al producto.';
            }
        }
        if ($error === '') {
            foreach (
                $formColors
                as
                $color
            ) {
                if (
                    !is_array(
                        $color
                    )
                ) {
                    continue;
                }
                $colorName =
                    trim(
                        (string) (
                            $color['name']
                            ?? ''
                        )
                    );
                $colorHex =
                    trim(
                        (string) (
                            $color['hex']
                            ?? ''
                        )
                    );
                if ($colorName === '') {
                    $error =
                        'Cada color debe tener un nombre.';
                    break;
                }
                $colorKey =
                    strtolower(
                        $colorName
                    );
                if (
                    isset(
                    $seenColors[
                        $colorKey
                    ]
                )
                ) {
                    $error =
                        'No agregues el mismo color dos veces.';
                    break;
                }
                $seenColors[
                    $colorKey
                ] = true;
                if (
                    $colorHex !== '' &&
                    !preg_match(
                        '/^#[0-9A-Fa-f]{6}$/',
                        $colorHex
                    )
                ) {
                    $error =
                        'Valor HEX no válido para el color ' .
                        $colorName .
                        '.';
                    break;
                }
                $stockValues =
                    $color['stock']
                    ?? [];
                foreach (
                    $availableSizes
                    as
                    $size
                ) {
                    $stockValue =
                        $stockValues[$size]
                        ?? '0';
                    if (
                        $stockValue === ''
                    ) {
                        $stockValue = '0';
                    }
                    if (
                        filter_var(
                            $stockValue,
                            FILTER_VALIDATE_INT
                        ) === false ||
                        (int) $stockValue < 0
                    ) {
                        $error =
                            'El stock debe ser cero o mayor.';
                        break 2;
                    }
                    $variants[] = [
                        'color_name' =>
                            $colorName,
                        'color_hex' =>
                            $colorHex,
                        'size' =>
                            $size,
                        'stock' =>
                            (int) 
                            $stockValue,
                    ];
                }
            }
        }
        /*
        |--------------------------------------------------------------------------
        | Upload Image
        |--------------------------------------------------------------------------
        */
        $uploadedImagePath = null;
        if ($error === '') {
            try {
                $uploadedImagePath =
                    upload_product_image(
                        $_FILES[
                            'main_image'
                        ]
                        ?? []
                    );
            } catch (
                RuntimeException
                $exception
            ) {
                $error =
                    $exception->getMessage();
            }
        }
        /*
        |--------------------------------------------------------------------------
        | Insert Product
        |--------------------------------------------------------------------------
        */
        if ($error === '') {
            try {
                $pdo->beginTransaction();
                /*
                |--------------------------------------------------------------------------
                | Product Slug
                |--------------------------------------------------------------------------
                */
                $slug =
                    generate_unique_product_slug(
                        $pdo,
                        $name
                    );
                /*
                |--------------------------------------------------------------------------
                | Product
                |--------------------------------------------------------------------------
                */
                $insertProduct =
                    $pdo->prepare(
                        'INSERT INTO products
                        (
                            category_id,
                            code,
                            name,
                            slug,
                            description,
                            price,
                            main_image,
                            is_active,
                            is_featured,
                            is_new
                        )
                        VALUES
                        (
                            :category_id,
                            :code,
                            :name,
                            :slug,
                            :description,
                            :price,
                            :main_image,
                            :is_active,
                            :is_featured,
                            :is_new
                        )'
                    );
                $insertProduct->execute([
                    'category_id' =>
                        (int) 
                        $categoryId,
                    'code' =>
                        $code,
                    'name' =>
                        $name,
                    'slug' =>
                        $slug,
                    'description' =>
                        $description !== ''
                        ? $description
                        : null,
                    'price' =>
                        $priceValue,
                    'main_image' =>
                        $uploadedImagePath,
                    'is_active' =>
                        $isActive
                        ? 1
                        : 0,
                    'is_featured' =>
                        $isFeatured
                        ? 1
                        : 0,
                    'is_new' =>
                        $isNew
                        ? 1
                        : 0,
                ]);
                $productId =
                    (int) 
                    $pdo->lastInsertId();
                save_product_wholesale($pdo, $productId, $wholesaleValues);
                save_product_cost($pdo, $productId, $costValues);
                save_product_sort_order($pdo, $productId, product_sort_order_from_post());
                /*
                |--------------------------------------------------------------------------
                | Product Variants
                |--------------------------------------------------------------------------
                */
                $insertVariant =
                    $pdo->prepare(
                        'INSERT INTO product_variants
                        (
                            product_id,
                            color_name,
                            color_hex,
                            size,
                            stock,
                            sku
                        )
                        VALUES
                        (
                            :product_id,
                            :color_name,
                            :color_hex,
                            :size,
                            :stock,
                            :sku
                        )'
                    );
                foreach (
                    $variants
                    as
                    $variant
                ) {
                    $sku =
                        product_sku_part(
                            $code
                        ) .
                        '-' .
                        product_sku_part(
                            $variant[
                                'color_name'
                            ]
                        ) .
                        '-' .
                        $variant['size'];
                    $insertVariant->execute([
                        'product_id' =>
                            $productId,
                        'color_name' =>
                            $variant[
                                'color_name'
                            ],
                        'color_hex' =>
                            $variant[
                                'color_hex'
                            ] !== ''
                            ? $variant[
                                'color_hex'
                            ]
                            : null,
                        'size' =>
                            $variant['size'],
                        'stock' =>
                            $variant['stock'],
                        'sku' =>
                            $sku,
                    ]);
                }
                /*
                |--------------------------------------------------------------------------
                | Commit
                |--------------------------------------------------------------------------
                */
                $pdo->commit();
                /*
                |--------------------------------------------------------------------------
                | Redirect
                |--------------------------------------------------------------------------
                */
                header(
                    'Location: ' .
                    APP_BASE_URL .
                    '/admin/products/?created=1'
                );
                exit;
            } catch (
                Throwable
                $exception
            ) {
                if (
                    $pdo->inTransaction()
                ) {
                    $pdo->rollBack();
                }
                /*
                |--------------------------------------------------------------------------
                | Delete Uploaded Image On Failure
                |--------------------------------------------------------------------------
                */
                if (
                    $uploadedImagePath
                    !== null
                ) {
                    $uploadedFile =
                        dirname(
                            __DIR__,
                            2
                        ) .
                        '/' .
                        $uploadedImagePath;
                    if (
                        is_file(
                            $uploadedFile
                        )
                    ) {
                        @unlink(
                            $uploadedFile
                        );
                    }
                }
                error_log(
                    $exception->getMessage()
                );
                $error =
                    'No se pudo crear el producto. Intenta nuevamente.';
            }
        }
    }
} ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Agregar producto | Administración <?= e(business_name()) ?>
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
        <a href="<?= e(APP_BASE_URL) ?>/admin/">
            <img class="admin-logo" src="<?= e(business_logo_url()) ?>" alt="<?= e(business_name()) ?>">
        </a>
        <div class="admin-user">
            <span> <?= e(
                $_SESSION['admin_name']
            ) ?> </span>
            <form method="post" action="<?= e(APP_BASE_URL) ?>/admin/logout.php"> <input type="hidden" name="csrf_token"
                    value="<?= e(csrf_token()) ?>"> <button class="logout-button" type="submit">
                    Cerrar sesión
                </button> </form>
        </div>
    </header>
    <div class="admin-layout">
        <aside class="sidebar">
            <p class="sidebar-title"> Administración
            </p>
            <?php require_once __DIR__ . '/../../includes/admin-nav.php'; ?>
            <?php render_admin_nav('products'); ?>
        </aside>
        <main class="admin-content">
            <div class="page-header">
                <div> <span class="page-eyebrow">
                        CATÁLOGO
                    </span>
                    <h1 class="page-title">
                        Agregar producto
                    </h1>
                    <p class="page-description">
                        Agrega la información del producto,
                        colores, tallas y existencias.
                    </p>
                </div>
                <a class="
                    admin-button
                    admin-button-outline
                " href="<?= e(APP_BASE_URL) ?>/admin/products/">
                    ← PRODUCTOS
                </a>
            </div>
            <?php if (
                $error !== ''
            ): ?>
                <div class="
                    admin-notice
                    admin-notice-error
                "> <?= e(
                    $error
                ) ?> </div> <?php endif; ?>
            <form
    class="admin-form"
    method="post"
    enctype="multipart/form-data"
>

    <input
        type="hidden"
        name="csrf_token"
        value="<?= e(
            csrf_token()
        ) ?>"
    >


    <div class="product-create-grid">

        <section class="form-section product-create-main">

            <div class="form-section-header">

                <h2>
                    Información del producto
                </h2>

                <p>
                    Información principal que se mostrará
                    en el catálogo.
                </p>

            </div>


            <div class="form-grid">


                <!-- Category -->

                <div class="form-group">

                    <label
                        class="form-label"
                        for="category_id"
                    >
                        Categoría
                    </label>

                    <select
                        class="form-select"
                        id="category_id"
                        name="category_id"
                        required
                    >

                        <option value="">
                            Seleccionar categoría
                        </option>


                        <?php foreach (
                            $categories
                            as
                            $category
                        ): ?>

                            <option
                                value="<?= (int)
                                    $category['id']
                                ?>"
                                <?= (
                                    $categoryId ===
                                    (string)
                                    $category['id']
                                )
                                    ? 'selected'
                                    : ''
                                ?>
                            >

                                <?= e(
                                    $category['name']
                                ) ?>

                                <?php if (
                                    (int)
                                    $category[
                                        'is_coming_soon'
                                    ] === 1
                                ): ?>

                                    — Próximamente

                                <?php endif; ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- Code -->

                <div class="form-group">

                    <label
                        class="form-label"
                        for="code"
                    >
                        Código del producto
                    </label>

                    <input
                        class="form-input"
                        id="code"
                        name="code"
                        type="text"
                        value="<?= e($code) ?>"
                        placeholder="AE-001"
                        required
                    >

                </div>


                <!-- Name -->

                <div class="form-group form-group-full">

                    <label
                        class="form-label"
                        for="name"
                    >
                        Nombre del producto
                    </label>

                    <input
                        class="form-input"
                        id="name"
                        name="name"
                        type="text"
                        value="<?= e($name) ?>"
                        placeholder="Flat H Camel"
                        required
                    >

                </div>


                <!-- Price -->

                <div class="form-group form-group-full">

                    <label
                        class="form-label"
                        for="price"
                    >
                        Precio
                    </label>

                    <input
                        class="form-input"
                        id="price"
                        name="price"
                        type="number"
                        min="0"
                        step="0.01"
                        value="<?= e($price) ?>"
                        placeholder="1390.00"
                    >

                    <p class="form-hint">
                        Déjalo vacío para mostrar
                        "Consultar precio".
                    </p>

                </div>

                <?php render_product_cost_field($pdo, $costForm); ?>

                <?php render_wholesale_fields($pdo, $wholesaleForm); ?>


                <!-- Description -->

                <div class="form-group form-group-full">

                    <label
                        class="form-label"
                        for="description"
                    >
                        Descripción
                    </label>

                    <textarea
                        class="form-textarea"
                        id="description"
                        name="description"
                        placeholder="Descripción del producto..."
                    ><?= e(
                        $description
                    ) ?></textarea>

                </div>


                <!-- Main Image -->

                <div class="form-group form-group-full">

                    <label
                        class="form-label"
                        for="main_image"
                    >
                        Imagen principal
                    </label>

                    <input
                        class="form-input"
                        id="main_image"
                        name="main_image"
                        type="file"
                        accept="
                            image/jpeg,
                            image/png,
                            image/webp
                        "
                        required
                    >

                    <p class="form-hint">
                        JPG, PNG o WEBP.
                        Tamaño máximo: 5 MB.
                    </p>

                </div>


            </div>

        </section>

        <div class="product-create-side">


            <!-- =================================================
                 VISIBILITY
                 ================================================= -->

            <section class="form-section">

                <div class="form-section-header">

                    <h2>
                        Visibilidad
                    </h2>

                    <p>
                        Controla cómo aparecerá el producto
                        dentro del catálogo.
                    </p>

                </div>


                <div class="product-visibility-grid">


                    <label class="visibility-option">

                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            <?= $isActive
                                ? 'checked'
                                : ''
                            ?>
                        >

                        <span class="visibility-option-content">

                            <strong>
                                Activo
                            </strong>

                            <small>
                                Mostrar producto en el catálogo.
                            </small>

                        </span>

                    </label>



                    <label class="visibility-option">

                        <input
                            type="checkbox"
                            name="is_featured"
                            value="1"
                            <?= $isFeatured
                                ? 'checked'
                                : ''
                            ?>
                        >

                        <span class="visibility-option-content">

                            <strong>
                                Destacado
                            </strong>

                            <small>
                                Marcar como producto destacado.
                            </small>

                        </span>

                    </label>



                    <label class="visibility-option">

                        <input
                            type="checkbox"
                            name="is_new"
                            value="1"
                            <?= $isNew
                                ? 'checked'
                                : ''
                            ?>
                        >

                        <span class="visibility-option-content">

                            <strong>
                                Nuevo
                            </strong>

                            <small>
                                Mostrar como producto nuevo.
                            </small>

                        </span>

                    </label>


                </div>

            </section>

            <?php render_product_sort_field($_SERVER['REQUEST_METHOD'] === 'POST' ? product_sort_order_from_post() : 0); ?>

            <section class="form-section">

                <div class="form-section-header">

                    <h2>
                        Colores, tallas y stock
                    </h2>

                    <p>
                        Agrega uno o más colores y define
                        las existencias disponibles por talla.
                    </p>

                </div>


                <div id="colorsContainer">


                    <?php foreach (
                        $formColors
                        as
                        $colorIndex =>
                        $color
                    ): ?>


                        <?php

                        $currentColorName =
                            trim(
                                (string) (
                                    $color['name']
                                    ?? ''
                                )
                            );


                        $currentColorHex =
                            trim(
                                (string) (
                                    $color['hex']
                                    ?? '#d8c6b5'
                                )
                            );


                        $currentStock =
                            $color['stock']
                            ?? [];

                        ?>


                        <div
                            class="color-block"
                            data-color-block
                        >


                            <div class="color-block-header">

                                <p class="color-block-title">
                                    Color
                                </p>

                                <button
                                    class="remove-color-button"
                                    type="button"
                                    data-remove-color
                                >
                                    Eliminar
                                </button>

                            </div>



                            <div class="color-details-grid">


                                <div class="form-group">

                                    <label class="form-label">
                                        Nombre del color
                                    </label>

                                    <input
                                        class="form-input"
                                        type="text"
                                        name="colors[<?= (int)
                                            $colorIndex
                                        ?>][name]"
                                        value="<?= e(
                                            $currentColorName
                                        ) ?>"
                                        placeholder="Camel"
                                        required
                                    >

                                </div>



                                <div class="form-group">

                                    <label class="form-label">
                                        Color
                                    </label>

                                    <input
                                        class="form-input"
                                        type="color"
                                        name="colors[<?= (int)
                                            $colorIndex
                                        ?>][hex]"
                                        value="<?= e(
                                            $currentColorHex
                                        ) ?>"
                                    >

                                </div>


                            </div>



                            <p class="stock-title">
                                Stock por talla
                            </p>


                            <div class="stock-grid product-create-stock-grid">


                                <?php foreach (
                                    $availableSizes
                                    as
                                    $size
                                ): ?>


                                    <div class="stock-item">

                                        <label>
                                            Talla
                                            <?= e($size) ?>
                                        </label>

                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            name="colors[<?= (int)
                                                $colorIndex
                                            ?>][stock][<?= e(
                                                $size
                                            ) ?>]"
                                            value="<?= e(
                                                (string) (
                                                    $currentStock[
                                                        $size
                                                    ]
                                                    ?? '0'
                                                )
                                            ) ?>"
                                        >

                                    </div>


                                <?php endforeach; ?>


                            </div>

                        </div>


                    <?php endforeach; ?>


                </div>



                <div class="add-color-wrapper">

                    <button
                        class="
                            admin-button
                            admin-button-outline
                        "
                        id="addColorButton"
                        type="button"
                    >
                        + AGREGAR COLOR
                    </button>

                </div>

            </section>


        </div>

    </div>



    <!-- =========================================================
         ACTIONS
         ========================================================= -->

    <div class="form-actions product-create-actions">

        <button
            class="admin-button"
            type="submit"
        >
            CREAR PRODUCTO
        </button>


        <a
            class="
                admin-button
                admin-button-outline
            "
            href="<?= e(
                APP_BASE_URL
            ) ?>/admin/products/"
        >
            CANCELAR
        </a>

    </div>


</form>
        </main>
    </div>
    <!-- =========================================================
     COLOR TEMPLATE
     ========================================================= --><template id="colorTemplate">
        <div class="color-block" data-color-block>
            <div class="color-block-header">
                <p class="color-block-title">
                    Color
                </p> <button class="remove-color-button" type="button" data-remove-color>
                    Eliminar color
                </button>
            </div>
            <div class="color-details-grid">
                <div class="form-group"> <label class="form-label">
                        Nombre del color
                    </label> <input class="form-input" type="text" name="colors[__INDEX__][name]" placeholder="Camel"
                        required> </div>
                <div class="form-group"> <label class="form-label">
                        Color
                    </label> <input class="form-input" type="color" name="colors[__INDEX__][hex]" value="#d8c6b5">
                </div>
            </div>
            <p class="stock-title">
                Stock por talla
            </p>
            <div class="stock-grid">
                <?php foreach (
                    $availableSizes
                    as
                    $size
                ): ?>
                    <div class="stock-item"> <label> Talla
                            <?= e(
                                $size
                            ) ?> </label> <input type="number" min="0" step="1" name="colors[__INDEX__][stock][<?= e(
                                  $size
                              ) ?>]" value="0"> </div>
                <?php endforeach; ?>
            </div>
        </div>
    </template>
    <script>document.addEventListener(
            "DOMContentLoaded",
            () => {
                const container =
                    document.getElementById(
                        "colorsContainer"
                    ); const template =
                        document.getElementById(
                            "colorTemplate"
                        ); const addButton =
                            document.getElementById(
                                "addColorButton"
                            );
                let colorIndex =
                    container.querySelectorAll(
                        "[data-color-block]"
                    ).length;
                function updateRemoveButtons() {
                    const blocks =
                        container.querySelectorAll(
                            "[data-color-block]"
                        ); blocks.forEach(
                            block => {
                                const button =
                                    block.querySelector(
                                        "[data-remove-color]"
                                    ); if (!button) {
                                        return;
                                    } button.style.display =
                                        blocks.length > 1
                                            ? ""
                                            : "none";
                            }
                        );
                }
                function addColor() {
                    let html =
                        template.innerHTML; html =
                            html.replaceAll(
                                "__INDEX__",
                                String(
                                    colorIndex
                                )
                            ); container.insertAdjacentHTML(
                                "beforeend",
                                html
                            ); colorIndex++; updateRemoveButtons();
                }
                addButton.addEventListener(
                    "click",
                    addColor
                );
                container.addEventListener(
                    "click",
                    event => {
                        const removeButton =
                            event.target.closest(
                                "[data-remove-color]"
                            ); if (!removeButton) {
                                return;
                            } const blocks =
                                container.querySelectorAll(
                                    "[data-color-block]"
                                ); if (
                            blocks.length <= 1
                        ) {
                            return;
                        } const block =
                            removeButton.closest(
                                "[data-color-block]"
                            ); if (block) {
                                block.remove();
                            } updateRemoveButtons();
                    }
                );
                updateRemoveButtons();
            }
        );</script>
</body>

</html>