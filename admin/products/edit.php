<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/product-functions.php';
require_once __DIR__ . '/../../includes/wholesale-functions.php';
require_once __DIR__ . '/../../includes/finance-functions.php';
require_admin();
/*
|--------------------------------------------------------------------------
| Available Sizes
|--------------------------------------------------------------------------
*/
$availableSizes = [
    '35',
    '36',
    '37',
    '38',
    '39',
    '40',
];
/*
|--------------------------------------------------------------------------
| Product ID
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {
    $productId =
        filter_var(
            $_POST['product_id']
            ?? null,
            FILTER_VALIDATE_INT
        );
} else {
    $productId =
        filter_input(
            INPUT_GET,
            'id',
            FILTER_VALIDATE_INT
        );
}
if (!$productId) {
    http_response_code(404);
    exit(
        'Producto no encontrado.'
    );
}
/*
|--------------------------------------------------------------------------
| Load Product
|--------------------------------------------------------------------------
*/
$productStatement =
    $pdo->prepare(
        'SELECT *
         FROM products
         WHERE id = :id
         LIMIT 1'
    );
$productStatement->execute([
    'id' => $productId,
]);
$product =
    $productStatement->fetch();
if (!$product) {
    http_response_code(404);
    exit(
        'Producto no encontrado.'
    );
}
/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/
$categoryStatement =
    $pdo->query(
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
| Existing Variants
|--------------------------------------------------------------------------
*/
$variantStatement =
    $pdo->prepare(
        'SELECT
            color_name,
            color_hex,
            size,
            stock
         FROM product_variants
         WHERE product_id = :product_id
         ORDER BY
            color_name ASC,
            size ASC'
    );
$variantStatement->execute([
    'product_id' =>
        $productId,
]);
$databaseVariants =
    $variantStatement->fetchAll();
/*
|--------------------------------------------------------------------------
| Group Existing Variants By Color
|--------------------------------------------------------------------------
*/
$existingColors = [];
foreach (
    $databaseVariants
    as
    $variant
) {
    $colorKey =
        strtolower(
            trim(
                $variant['color_name']
            )
        );
    if (
        !isset(
        $existingColors[$colorKey]
    )
    ) {
        $stock = [];
        foreach (
            $availableSizes
            as
            $size
        ) {
            $stock[$size] = '0';
        }
        $existingColors[$colorKey] = [
            'name' =>
                $variant['color_name'],
            'hex' =>
                $variant['color_hex']
                ?: '#d8c6b5',
            'stock' =>
                $stock,
        ];
    }
    $existingColors[
        $colorKey
    ]['stock'][
        $variant['size']
    ] =
        (string) 
        $variant['stock'];
}
if (
    count($existingColors)
    === 0
) {
    $existingColors = [
        [
            'name' => '',
            'hex' => '#d8c6b5',
            'stock' => [
                '35' => '0',
                '36' => '0',
                '37' => '0',
                '38' => '0',
                '39' => '0',
                '40' => '0',
            ],
        ],
    ];
}
/*
|--------------------------------------------------------------------------
| Form Values
|--------------------------------------------------------------------------
*/
$isPost =
    $_SERVER['REQUEST_METHOD']
    === 'POST';
$error = '';
$categoryId =
    (string) (
        $isPost
        ? (
            $_POST['category_id']
            ?? ''
        )
        : $product['category_id']
    );
$code =
    trim(
        (string) (
            $isPost
            ? (
                $_POST['code']
                ?? ''
            )
            : $product['code']
        )
    );
$name =
    trim(
        (string) (
            $isPost
            ? (
                $_POST['name']
                ?? ''
            )
            : $product['name']
        )
    );
$description =
    trim(
        (string) (
            $isPost
            ? (
                $_POST['description']
                ?? ''
            )
            : (
                $product['description']
                ?? ''
            )
        )
    );
$price =
    trim(
        (string) (
            $isPost
            ? (
                $_POST['price']
                ?? ''
            )
            : (
                $product['price']
                ?? ''
            )
        )
    );
$isActive =
    $isPost
    ? isset(
    $_POST['is_active']
)
    : (
        (int) 
        $product['is_active']
        === 1
    );
$isFeatured =
    $isPost
    ? isset(
    $_POST['is_featured']
)
    : (
        (int) 
        $product['is_featured']
        === 1
    );
$isNew =
    $isPost
    ? isset(
    $_POST['is_new']
)
    : (
        (int) 
        $product['is_new']
        === 1
    );
$wholesaleForm = wholesale_form_values($isPost, $product);
$wholesaleValues = null;
$costForm = product_cost_form_value($isPost, $product);
$costValues = null;
$formColors =
    $isPost
    ? (
        $_POST['colors']
        ?? []
    )
    : array_values(
        $existingColors
    );
if (
    !is_array(
        $formColors
    )
) {
    $formColors = [];
}
/*
|--------------------------------------------------------------------------
| Update Product
|--------------------------------------------------------------------------
*/
if ($isPost) {
    $csrfToken =
        $_POST['csrf_token']
        ?? null;
    if (
        !verify_csrf_token(
            $csrfToken
        )
    ) {
        $error =
            'Token de seguridad inválido.';
    } elseif (
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
    }    /*
   |--------------------------------------------------------------------------
   | Category Validation
   |--------------------------------------------------------------------------
   */
    if ($error === '') {
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
                (int) 
                $categoryId,
        ]);
        if (
            !$categoryCheck->fetch()
        ) {
            $error =
                'La categoría seleccionada no existe.';
        }
    }
    /*
    |--------------------------------------------------------------------------
    | Unique Product Code
    |--------------------------------------------------------------------------
    */
    if ($error === '') {
        $codeCheck =
            $pdo->prepare(
                'SELECT COUNT(*)
                 FROM products
                 WHERE
                    code = :code
                    AND id != :id'
            );
        $codeCheck->execute([
            'code' =>
                $code,
            'id' =>
                $productId,
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
        $normalizedPrice =
            str_replace(
                ',',
                '.',
                $price
            );
        if (
            !is_numeric(
                $normalizedPrice
            ) ||
            (float) 
            $normalizedPrice < 0
        ) {
            $error =
                'Ingresa un precio válido.';
        } else {
            $priceValue =
                number_format(
                    (float) 
                    $normalizedPrice,
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
    | Colors And Variants
    |--------------------------------------------------------------------------
    */
    $variants = [];
    $seenColors = [];
    if (
        $error === '' &&
        count($formColors) === 0
    ) {
        $error =
            'Agrega al menos un color al producto.';
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
                    'El valor HEX del color no es válido.';
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
                    $stockValues[
                        $size
                    ]
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
                    (int) 
                    $stockValue < 0
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
    | Optional New Image
    |--------------------------------------------------------------------------
    */
    $oldImagePath =
        $product['main_image'];
    $newImagePath = null;
    $finalImagePath =
        $oldImagePath;
    $uploadedFile =
        $_FILES['main_image']
        ?? null;
    if (
        $error === '' &&
        is_array(
            $uploadedFile
        ) &&
        isset(
        $uploadedFile['error']
    ) &&
        (int) 
        $uploadedFile['error']
        !== UPLOAD_ERR_NO_FILE
    ) {
        try {
            $newImagePath =
                upload_product_image(
                    $uploadedFile
                );
            $finalImagePath =
                $newImagePath;
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
    | Database Update
    |--------------------------------------------------------------------------
    */
    if ($error === '') {
        try {
            $pdo->beginTransaction();
            $slug =
                generate_unique_product_slug(
                    $pdo,
                    $name,
                    $productId
                );
            /*
            |--------------------------------------------------------------------------
            | Update Product
            |--------------------------------------------------------------------------
            */
            $updateProduct =
                $pdo->prepare(
                    'UPDATE products
                     SET
                        category_id = :category_id,
                        code = :code,
                        name = :name,
                        slug = :slug,
                        description = :description,
                        price = :price,
                        main_image = :main_image,
                        is_active = :is_active,
                        is_featured = :is_featured,
                        is_new = :is_new
                     WHERE id = :id'
                );
            $updateProduct->execute([
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
                    $finalImagePath,
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
                'id' =>
                    $productId,
            ]);
            save_product_wholesale($pdo, $productId, $wholesaleValues);
            save_product_cost($pdo, $productId, $costValues);
            /*
            |--------------------------------------------------------------------------
            | Replace Variants
            |--------------------------------------------------------------------------
            */
            $deleteVariants =
                $pdo->prepare(
                    'DELETE FROM product_variants
                     WHERE product_id = :product_id'
                );
            $deleteVariants->execute([
                'product_id' =>
                    $productId,
            ]);
            /*
            |--------------------------------------------------------------------------
            | Insert Updated Variants
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
            | Delete Previous Image After Successful Commit
            |--------------------------------------------------------------------------
            */
            if (
                $newImagePath !== null &&
                $oldImagePath !== null &&
                $newImagePath !==
                $oldImagePath
            ) {
                delete_product_image(
                    $oldImagePath
                );
            }
            /*
            |--------------------------------------------------------------------------
            | Redirect
            |--------------------------------------------------------------------------
            */
            header(
                'Location: ' .
                APP_BASE_URL .
                '/admin/products/?updated=1'
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
            if (
                $newImagePath
                !== null
            ) {
                delete_product_image(
                    $newImagePath
                );
            }
            error_log(
                $exception->getMessage()
            );
            $error =
                'No se pudo actualizar el producto. Intenta nuevamente.';
        }
    }
} ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Editar producto | Administración <?= e(business_name()) ?>
    </title>

    <link href="<?= e(business_favicon_url()) ?>" rel="icon">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../admin.css?v=<?= (int) @filemtime(__DIR__ . '/../admin.css') ?>">
</head>

<body>

<header class="admin-header">

    <a href="<?= e(APP_BASE_URL) ?>/admin/">
        <img
            class="admin-logo"
            src="<?= e(business_logo_url()) ?>"
            alt="<?= e(business_name()) ?>"
        >
    </a>

    <div class="admin-user">

        <span>
            <?= e($_SESSION['admin_name']) ?>
        </span>

        <form
            method="post"
            action="<?= e(APP_BASE_URL) ?>/admin/logout.php"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <button
                class="logout-button"
                type="submit"
            >
                Cerrar sesión
            </button>
        </form>

    </div>

</header>


<div class="admin-layout">

    <aside class="sidebar">

        <p class="sidebar-title">
            Administración
        </p>

            <?php require_once __DIR__ . '/../../includes/admin-nav.php'; ?>
            <?php render_admin_nav('products'); ?>

    </aside>


    <main class="admin-content">

        <div class="page-header">

            <div>
                <span class="page-eyebrow">
                    CATÁLOGO
                </span>

                <h1 class="page-title">
                    Editar producto
                </h1>

                <p class="page-description">
                    Actualiza la información del producto, imagen, colores, tallas y existencias.
                </p>
            </div>

            <a
                class="admin-button admin-button-outline"
                href="<?= e(APP_BASE_URL) ?>/admin/products/"
            >
                ← PRODUCTOS
            </a>

        </div>


        <?php if ($error !== ''): ?>

            <div class="admin-notice admin-notice-error">
                <?= e($error) ?>
            </div>

        <?php endif; ?>


        <form
            class="admin-form"
            method="post"
            enctype="multipart/form-data"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="product_id"
                value="<?= (int) $productId ?>"
            >


            <div class="product-create-grid">

                <!-- =====================================================
                     LEFT COLUMN
                     PRODUCT INFORMATION
                     ===================================================== -->

                <section class="form-section product-create-main">

                    <div class="form-section-header">
                        <h2>
                            Información del producto
                        </h2>

                        <p>
                            Actualiza la información principal que se mostrará en el catálogo.
                        </p>
                    </div>


                    <div class="form-grid">

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

                                <?php foreach ($categories as $category): ?>

                                    <option
                                        value="<?= (int) $category['id'] ?>"
                                        <?= (
                                            $categoryId === (string) $category['id']
                                        ) ? 'selected' : '' ?>
                                    >
                                        <?= e($category['name']) ?>

                                        <?php if (
                                            (int) $category['is_coming_soon'] === 1
                                        ): ?>
                                            — Próximamente
                                        <?php endif; ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


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
                                required
                            >

                        </div>


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
                                required
                            >

                        </div>


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
                                Déjalo vacío para mostrar "Consultar precio".
                            </p>

                        </div>

                        <?php render_product_cost_field($pdo, $costForm); ?>

                        <?php render_wholesale_fields($pdo, $wholesaleForm); ?>


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
                            ><?= e($description) ?></textarea>

                        </div>


                        <?php if (!empty($product['main_image'])): ?>

                            <div class="form-group form-group-full">

                                <label class="form-label">
                                    Imagen actual
                                </label>

                                <div class="current-image">
                                    <img
                                        src="../../<?= e($product['main_image']) ?>"
                                        alt="<?= e($product['name']) ?>"
                                    >
                                </div>

                            </div>

                        <?php endif; ?>


                        <div class="form-group form-group-full">

                            <label
                                class="form-label"
                                for="main_image"
                            >
                                Reemplazar imagen principal
                            </label>

                            <input
                                class="form-input"
                                id="main_image"
                                name="main_image"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                            >

                            <p class="form-hint">
                                Déjalo vacío para conservar la imagen actual. JPG, PNG o WEBP. Tamaño máximo: 5 MB.
                            </p>

                        </div>

                    </div>

                </section>


                <!-- =====================================================
                     RIGHT COLUMN
                     ===================================================== -->

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
                                Controla cómo aparecerá el producto dentro del catálogo.
                            </p>
                        </div>


                        <div class="product-visibility-grid">

                            <label class="visibility-option">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    <?= $isActive ? 'checked' : '' ?>
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
                                    <?= $isFeatured ? 'checked' : '' ?>
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
                                    <?= $isNew ? 'checked' : '' ?>
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


                    <!-- =================================================
                         COLORS / SIZES / STOCK
                         ================================================= -->

                    <section class="form-section">

                        <div class="form-section-header">
                            <h2>
                                Colores, tallas y stock
                            </h2>

                            <p>
                                Edita la disponibilidad del producto por color y talla.
                            </p>
                        </div>


                        <div id="colorsContainer">

                            <?php foreach (
                                $formColors as $colorIndex => $color
                            ): ?>

                                <?php

                                $currentColorName = trim(
                                    (string) (
                                        $color['name'] ?? ''
                                    )
                                );

                                $currentColorHex = trim(
                                    (string) (
                                        $color['hex'] ?? '#d8c6b5'
                                    )
                                );

                                $currentStock =
                                    $color['stock'] ?? [];

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
                                                name="colors[<?= e((string) $colorIndex) ?>][name]"
                                                value="<?= e($currentColorName) ?>"
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
                                                name="colors[<?= e((string) $colorIndex) ?>][hex]"
                                                value="<?= e($currentColorHex) ?>"
                                            >

                                        </div>

                                    </div>


                                    <p class="stock-title">
                                        Stock por talla
                                    </p>


                                    <div class="stock-grid product-create-stock-grid">

                                        <?php foreach ($availableSizes as $size): ?>

                                            <div class="stock-item">

                                                <label>
                                                    Talla <?= e($size) ?>
                                                </label>

                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    name="colors[<?= e((string) $colorIndex) ?>][stock][<?= e($size) ?>]"
                                                    value="<?= e(
                                                        (string) (
                                                            $currentStock[$size] ?? '0'
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
                                class="admin-button admin-button-outline"
                                id="addColorButton"
                                type="button"
                            >
                                + AGREGAR COLOR
                            </button>

                        </div>

                    </section>

                </div>

            </div>


            <div class="form-actions product-create-actions">

                <button
                    class="admin-button"
                    type="submit"
                >
                    GUARDAR CAMBIOS
                </button>

                <a
                    class="admin-button admin-button-outline"
                    href="<?= e(APP_BASE_URL) ?>/admin/products/"
                >
                    CANCELAR
                </a>

            </div>

        </form>

    </main>

</div>


<!-- =========================================================
     COLOR TEMPLATE
     ========================================================= -->

<template id="colorTemplate">

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
                    name="colors[__INDEX__][name]"
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
                    name="colors[__INDEX__][hex]"
                    value="#d8c6b5"
                >

            </div>

        </div>


        <p class="stock-title">
            Stock por talla
        </p>


        <div class="stock-grid product-create-stock-grid">

            <?php foreach ($availableSizes as $size): ?>

                <div class="stock-item">

                    <label>
                        Talla <?= e($size) ?>
                    </label>

                    <input
                        type="number"
                        min="0"
                        step="1"
                        name="colors[__INDEX__][stock][<?= e($size) ?>]"
                        value="0"
                    >

                </div>

            <?php endforeach; ?>

        </div>

    </div>

</template>


<script>

document.addEventListener(
    "DOMContentLoaded",
    () => {

        const container =
            document.getElementById(
                "colorsContainer"
            );

        const template =
            document.getElementById(
                "colorTemplate"
            );

        const addButton =
            document.getElementById(
                "addColorButton"
            );

        let colorIndex =
            Date.now();


        function updateRemoveButtons() {

            const blocks =
                container.querySelectorAll(
                    "[data-color-block]"
                );

            blocks.forEach(
                block => {

                    const button =
                        block.querySelector(
                            "[data-remove-color]"
                        );

                    if (!button) {
                        return;
                    }

                    button.style.display =
                        blocks.length > 1
                            ? ""
                            : "none";

                }
            );

        }


        function addColor() {

            let html =
                template.innerHTML;

            html = html.replaceAll(
                "__INDEX__",
                String(colorIndex)
            );

            container.insertAdjacentHTML(
                "beforeend",
                html
            );

            colorIndex++;

            updateRemoveButtons();

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
                    );

                if (!removeButton) {
                    return;
                }

                const blocks =
                    container.querySelectorAll(
                        "[data-color-block]"
                    );

                if (blocks.length <= 1) {
                    return;
                }

                const block =
                    removeButton.closest(
                        "[data-color-block]"
                    );

                if (block) {
                    block.remove();
                }

                updateRemoveButtons();

            }
        );


        updateRemoveButtons();

    }
);

</script>

</body>

</html>