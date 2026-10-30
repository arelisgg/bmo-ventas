<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/product-functions.php';

require_admin();


/*
|--------------------------------------------------------------------------
| Product ID
|--------------------------------------------------------------------------
*/

$productId =
    filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );

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
        'SELECT
            id,
            code,
            name,
            main_image
         FROM products
         WHERE id = :id
         LIMIT 1'
    );

$productStatement->execute([
    'id' =>
        $productId,
]);

$product =
    $productStatement->fetch();

if (!$product) {
    http_response_code(404);

    exit(
        'Producto no encontrado.'
    );
}


$error = '';


/*
|--------------------------------------------------------------------------
| Save Gallery
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    if (
        !verify_csrf_token(
            $_POST['csrf_token']
            ?? null
        )
    ) {
        $error =
            'Token de seguridad inválido.';
    }


    /*
    |--------------------------------------------------------------------------
    | Images To Remove
    |--------------------------------------------------------------------------
    */

    $removeIds =
        $_POST['remove_images']
        ?? [];

    if (
        !is_array(
            $removeIds
        )
    ) {
        $removeIds = [];
    }

    $removeIds =
        array_values(
            array_filter(
                array_map(
                    static function (mixed $value): ?int {

                        $validated =
                            filter_var(
                                $value,
                                FILTER_VALIDATE_INT
                            );

                        return
                            $validated
                            ? (int) 
                            $validated
                            : null;
                    },
                    $removeIds
                )
            )
        );


    /*
    |--------------------------------------------------------------------------
    | Current Gallery
    |--------------------------------------------------------------------------
    */

    $currentImagesStatement =
        $pdo->prepare(
            'SELECT
                id,
                image_path,
                sort_order
             FROM product_images
             WHERE product_id = :product_id
             ORDER BY
                sort_order ASC,
                id ASC'
        );

    $currentImagesStatement->execute([
        'product_id' =>
            $productId,
    ]);

    $currentImages =
        $currentImagesStatement->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | New Uploads
    |--------------------------------------------------------------------------
    */

    $newFiles =
        normalize_multiple_uploads(
            $_FILES['gallery_images']
            ?? []
        );


    /*
    |--------------------------------------------------------------------------
    | Maximum Gallery Size
    |--------------------------------------------------------------------------
    */

    $remainingImageCount = 0;

    foreach (
        $currentImages
        as
        $image
    ) {
        if (
            !in_array(
                (int) $image['id'],
                $removeIds,
                true
            )
        ) {
            $remainingImageCount++;
        }
    }

    $finalImageCount =
        $remainingImageCount +
        count($newFiles);


    if (
        $error === '' &&
        $finalImageCount > 8
    ) {
        $error =
            'Un producto puede tener un máximo de 8 imágenes adicionales en la galería.';
    }


    /*
    |--------------------------------------------------------------------------
    | Upload New Files
    |--------------------------------------------------------------------------
    */

    $newImagePaths = [];

    if (
        $error === '' &&
        count($newFiles) > 0
    ) {
        try {

            $newImagePaths =
                upload_product_gallery_images(
                    $_FILES[
                        'gallery_images'
                    ]
                );

        } catch (
            Throwable $exception
        ) {
            $error =
                $exception->getMessage();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Sort Values
    |--------------------------------------------------------------------------
    */

    $sortValues =
        $_POST['sort_order']
        ?? [];

    if (
        !is_array(
            $sortValues
        )
    ) {
        $sortValues = [];
    }


    /*
    |--------------------------------------------------------------------------
    | Database Transaction
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        $imagesToDelete = [];

        try {

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Remove Selected Images
            |--------------------------------------------------------------------------
            */

            if (
                count($removeIds)
                > 0
            ) {

                $selectImage =
                    $pdo->prepare(
                        'SELECT
                            id,
                            image_path
                         FROM product_images
                         WHERE
                            id = :id
                            AND product_id = :product_id
                         LIMIT 1'
                    );

                $deleteImage =
                    $pdo->prepare(
                        'DELETE FROM product_images
                         WHERE
                            id = :id
                            AND product_id = :product_id'
                    );


                foreach (
                    $removeIds
                    as
                    $imageId
                ) {

                    $selectImage->execute([
                        'id' =>
                            $imageId,

                        'product_id' =>
                            $productId,
                    ]);

                    $image =
                        $selectImage->fetch();


                    if (!$image) {
                        continue;
                    }


                    $imagesToDelete[] =
                        $image[
                            'image_path'
                        ];


                    $deleteImage->execute([
                        'id' =>
                            $imageId,

                        'product_id' =>
                            $productId,
                    ]);
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Update Existing Sort Order
            |--------------------------------------------------------------------------
            */

            $updateSort =
                $pdo->prepare(
                    'UPDATE product_images
                     SET sort_order = :sort_order
                     WHERE
                        id = :id
                        AND product_id = :product_id'
                );


            foreach (
                $sortValues
                as
                $imageId =>
                $sortOrder
            ) {

                $validatedImageId =
                    filter_var(
                        $imageId,
                        FILTER_VALIDATE_INT
                    );

                $validatedSortOrder =
                    filter_var(
                        $sortOrder,
                        FILTER_VALIDATE_INT
                    );


                if (
                    !$validatedImageId ||
                    $validatedSortOrder
                    === false
                ) {
                    continue;
                }


                if (
                    in_array(
                        (int) 
                        $validatedImageId,
                        $removeIds,
                        true
                    )
                ) {
                    continue;
                }


                $updateSort->execute([
                    'sort_order' =>
                        max(
                            0,
                            (int) 
                            $validatedSortOrder
                        ),

                    'id' =>
                        (int) 
                        $validatedImageId,

                    'product_id' =>
                        $productId,
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | Find Next Sort Order
            |--------------------------------------------------------------------------
            */

            $maxSortStatement =
                $pdo->prepare(
                    'SELECT
                        COALESCE(
                            MAX(sort_order),
                            0
                        )
                     FROM product_images
                     WHERE product_id = :product_id'
                );

            $maxSortStatement->execute([
                'product_id' =>
                    $productId,
            ]);

            $nextSortOrder =
                (int) 
                $maxSortStatement
                    ->fetchColumn();


            /*
            |--------------------------------------------------------------------------
            | Insert New Gallery Images
            |--------------------------------------------------------------------------
            */

            $insertImage =
                $pdo->prepare(
                    'INSERT INTO product_images
                    (
                        product_id,
                        image_path,
                        sort_order
                    )
                    VALUES
                    (
                        :product_id,
                        :image_path,
                        :sort_order
                    )'
                );


            foreach (
                $newImagePaths
                as
                $imagePath
            ) {

                $nextSortOrder++;


                $insertImage->execute([
                    'product_id' =>
                        $productId,

                    'image_path' =>
                        $imagePath,

                    'sort_order' =>
                        $nextSortOrder,
                ]);
            }


            $pdo->commit();


            /*
            |--------------------------------------------------------------------------
            | Delete Physical Files After Commit
            |--------------------------------------------------------------------------
            */

            foreach (
                $imagesToDelete
                as
                $imagePath
            ) {
                delete_product_image(
                    $imagePath
                );
            }


            header(
                'Location: ' .
                APP_BASE_URL .
                '/admin/products/images.php?id=' .
                $productId .
                '&updated=1'
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


            foreach (
                $newImagePaths
                as
                $imagePath
            ) {
                delete_product_image(
                    $imagePath
                );
            }


            error_log(
                $exception->getMessage()
            );


            $error =
                'No se pudo actualizar la galería del producto.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Load Gallery
|--------------------------------------------------------------------------
*/

$galleryStatement =
    $pdo->prepare(
        'SELECT
            id,
            image_path,
            sort_order
         FROM product_images
         WHERE product_id = :product_id
         ORDER BY
            sort_order ASC,
            id ASC'
    );

$galleryStatement->execute([
    'product_id' =>
        $productId,
]);

$galleryImages =
    $galleryStatement->fetchAll();

?>
<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Imágenes del producto | Administración <?= e(business_name()) ?>
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="<?= e(business_favicon_url()) ?>" rel="icon" />
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

            <span>
                <?= e(
                    $_SESSION['admin_name']
                ) ?>
            </span>

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
                        GALERÍA DEL PRODUCTO
                    </span>

                    <h1 class="page-title">
                        Imágenes
                    </h1>

                    <p class="page-description">

                        <?= e(
                            $product['code']
                        ) ?>

                        ·

                        <?= e(
                            $product['name']
                        ) ?>

                    </p>

                </div> <a class="
                    admin-button
                    admin-button-outline
                " href="<?= e(APP_BASE_URL) ?>/admin/products/">
                    ← PRODUCTOS
                </a>

            </div>
            <?php if (
                isset(
                $_GET['updated']
            )
            ): ?>

                <div class="
                    admin-notice
                    admin-notice-success
                ">
                    La galería del producto se actualizó correctamente.
                </div>

            <?php endif; ?>
            <?php if (
                $error !== ''
            ): ?>

                <div class="
                    admin-notice
                    admin-notice-error
                ">
                    <?= e($error) ?>
                </div>

            <?php endif; ?>
            <form class="admin-form" method="post" enctype="multipart/form-data"> <input type="hidden" name="csrf_token"
                    value="<?= e(
                        csrf_token()
                    ) ?>">
                <!-- MAIN IMAGE -->

                <section class="form-section">
                    <div class="form-section-header">

                        <h2>
                            Imagen principal
                        </h2>

                        <p>
                            La imagen principal se administra
                            desde Editar producto.
                        </p>

                    </div> <?php if (
                        !empty(
                        $product['main_image']
                    )
                    ): ?>

                        <div class="gallery-main-image">

                            <img src="../../<?= e(
                                $product[
                                    'main_image'
                                ]
                            ) ?>" alt="<?= e(
                                 $product['name']
                             ) ?>">

                            <span>
                                PRINCIPAL
                            </span>

                        </div>

                    <?php endif; ?>
                </section>
                <!-- GALLERY -->

                <section class="form-section">
                    <div class="form-section-header">

                        <h2>
                            Galería
                        </h2>

                        <p>
                            Agrega hasta 8 imágenes adicionales
                            del producto.
                        </p>

                    </div>
                    <?php if (
                        count(
                            $galleryImages
                        ) > 0
                    ): ?>
                        <div class="admin-gallery-grid"> <?php foreach (
                            $galleryImages
                            as
                            $image
                        ): ?>
                                <article class="admin-gallery-card"> <img src="../../<?= e(
                                    $image[
                                        'image_path'
                                    ]
                                ) ?>" alt="<?= e(
                                     $product[
                                         'name'
                                     ]
                                 ) ?>">
                                    <div class="admin-gallery-card-controls"> <label>

                                            <span>
                                                Orden
                                            </span>

                                            <input type="number" min="0" step="1" name="sort_order[<?= (int) 
                                                $image['id']
                                                ?>]" value="<?= (int) 
                                                $image[
                                                    'sort_order'
                                                ]
                                                ?>">

                                        </label>
                                        <label class="
                                            admin-gallery-remove
                                        ">

                                            <input type="checkbox" name="remove_images[]" value="<?= (int) 
                                                $image['id']
                                                ?>">

                                            Eliminar

                                        </label>
                                    </div>
                                </article> <?php endforeach; ?>
                        </div> <?php else: ?>
                        <div class="gallery-empty">

                            Aún no hay imágenes adicionales.

                        </div> <?php endif; ?>
                    <div class="gallery-upload">

                        <label class="form-label" for="gallery_images">
                            Agregar imágenes
                        </label> <input class="form-input" id="gallery_images" name="gallery_images[]" type="file"
                            multiple accept="
                            image/jpeg,
                            image/png,
                            image/webp
                        ">
                        <p class="form-hint">
                            Puedes seleccionar varios archivos
                            JPG, PNG o WEBP.
                            Máximo 5 MB por imagen.
                        </p>

                    </div>
                </section>
                <div class="form-actions">

                    <button class="admin-button" type="submit">
                        GUARDAR GALERÍA
                    </button> <a class="
                        admin-button
                        admin-button-outline
                    " href="<?= e(APP_BASE_URL) ?>/admin/products/edit.php?id=<?= (int) 
                          $productId
                          ?>">
                        EDITAR PRODUCTO
                    </a>

                </div>
            </form>
        </main>
    </div>
</body>

</html>