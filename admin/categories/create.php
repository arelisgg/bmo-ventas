<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/category-functions.php';

require_admin();


$error = '';


$name =
    trim(
        (string) (
            $_POST['name']
            ?? ''
        )
    );


$sortOrder =
    trim(
        (string) (
            $_POST['sort_order']
            ?? '0'
        )
    );


$isActive =
    !isset($_POST['submitted']) ||
    isset($_POST['is_active']);


$isComingSoon =
    isset($_POST['is_coming_soon']);


/*
|--------------------------------------------------------------------------
| Create Category
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    /*
    |--------------------------------------------------------------------------
    | Validation
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

    } elseif (
        $name === ''
    ) {

        $error =
            'El nombre de la categoría es obligatorio.';

    } elseif (
        filter_var(
            $sortOrder,
            FILTER_VALIDATE_INT
        ) === false
    ) {

        $error =
            'El orden debe ser un número válido.';
    }


    /*
    |--------------------------------------------------------------------------
    | Upload Image
    |--------------------------------------------------------------------------
    */

    $imagePath = null;


    if ($error === '') {

        try {

            $imagePath =
                upload_category_image(
                    $_FILES['image']
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
    | Save Category
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        try {

            $slug =
                generate_unique_category_slug(
                    $pdo,
                    $name
                );


            $statement =
                $pdo->prepare(
                    'INSERT INTO categories
                    (
                        name,
                        slug,
                        image,
                        is_active,
                        is_coming_soon,
                        sort_order
                    )
                    VALUES
                    (
                        :name,
                        :slug,
                        :image,
                        :is_active,
                        :is_coming_soon,
                        :sort_order
                    )'
                );


            $statement->execute([
                'name' =>
                    $name,

                'slug' =>
                    $slug,

                'image' =>
                    $imagePath,

                'is_active' =>
                    $isActive
                    ? 1
                    : 0,

                'is_coming_soon' =>
                    $isComingSoon
                    ? 1
                    : 0,

                'sort_order' =>
                    (int) $sortOrder,
            ]);


            header(
                'Location: ' .
                APP_BASE_URL .
                '/admin/categories/?created=1'
            );

            exit;

        } catch (
            Throwable
            $exception
        ) {

            /*
            |--------------------------------------------------------------------------
            | Remove Uploaded Image On Failure
            |--------------------------------------------------------------------------
            */

            if (
                $imagePath !== null
            ) {

                delete_category_image(
                    $imagePath
                );
            }


            error_log(
                $exception->getMessage()
            );


            $error =
                'No se pudo crear la categoría.';
        }
    }
}

?>
<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Agregar categoría | Administración <?= e(business_name()) ?>
    </title>


    <link href="<?= e(business_favicon_url()) ?>" rel="icon">


    <link rel="stylesheet" href="../admin.css?v=<?= (int) @filemtime(__DIR__ . '/../admin.css') ?>">

</head>


<body>


    <header class="admin-header">

        <a href="<?= e(APP_BASE_URL) ?>/admin/">

            <img class="admin-logo" src="<?= e(business_logo_url()) ?>" alt="<?= e(business_name()) ?>">

        </a>

    </header>



    <div class="admin-layout">


        <!-- =========================================================
         SIDEBAR
         ========================================================= -->

        <aside class="sidebar">

            <p class="sidebar-title">
                Administración
            </p>


            <?php require_once __DIR__ . '/../../includes/admin-nav.php'; ?>
            <?php render_admin_nav('categories'); ?>

        </aside>



        <!-- =========================================================
         CONTENT
         ========================================================= -->

        <main class="admin-content">


            <!-- =====================================================
             PAGE HEADER
             ===================================================== -->

            <div class="page-header">

                <div>

                    <span class="page-eyebrow">
                        CATÁLOGO
                    </span>

                    <h1 class="page-title">
                        Agregar categoría
                    </h1>

                    <p class="page-description">
                        Crea una nueva categoría para organizar
                        los productos del catálogo.
                    </p>

                </div>


                <a class="
                    admin-button
                    admin-button-outline
                " href="<?= e(APP_BASE_URL) ?>/admin/categories/">
                    ← CATEGORÍAS
                </a>

            </div>



            <!-- =====================================================
             ERROR
             ===================================================== -->

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

            <form class="admin-form" method="post" enctype="multipart/form-data">

                <input type="hidden" name="csrf_token" value="<?= e(
                    csrf_token()
                ) ?>">

                <input type="hidden" name="submitted" value="1">


                <div class="category-create-grid">


                    <section class="form-section">

                        <div class="form-section-header">

                            <h2>
                                Información de la categoría
                            </h2>

                            <p>
                                Define el nombre y el orden
                                de visualización.
                            </p>

                        </div>


                        <div class="category-form-fields">


                            <!-- Category Name -->

                            <div class="form-group">

                                <label class="form-label" for="name">
                                    Nombre de la categoría
                                </label>

                                <input class="form-input" id="name" name="name" type="text" value="<?= e($name) ?>"
                                    placeholder="Flats" required>

                            </div>


                            <!-- Sort Order -->

                            <div class="form-group">

                                <label class="form-label" for="sort_order">
                                    Orden
                                </label>

                                <input class="form-input" id="sort_order" name="sort_order" type="number" min="0"
                                    step="1" value="<?= e(
                                        $sortOrder
                                    ) ?>">

                                <p class="form-hint">
                                    Los números menores aparecen primero.
                                </p>

                            </div>


                        </div>

                    </section>
                    <section class="form-section">

                        <div class="form-section-header">

                            <h2>
                                Visibilidad
                            </h2>

                            <p>
                                Controla cómo aparecerá la categoría
                                en el catálogo.
                            </p>

                        </div>


                        <div class="category-visibility-grid">


                            <label class="visibility-option">

                                <input type="checkbox" name="is_active" value="1" <?= $isActive
                                    ? 'checked'
                                    : ''
                                    ?>>

                                <span class="visibility-option-content">

                                    <strong>
                                        Activa
                                    </strong>

                                    <small>
                                        Mostrar esta categoría
                                        en el catálogo.
                                    </small>

                                </span>

                            </label>



                            <label class="visibility-option">

                                <input type="checkbox" name="is_coming_soon" value="1" <?= $isComingSoon
                                    ? 'checked'
                                    : ''
                                    ?>>

                                <span class="visibility-option-content">

                                    <strong>
                                        Próximamente
                                    </strong>

                                    <small>
                                        Mostrar el mensaje de
                                        próximamente en lugar de productos.
                                    </small>

                                </span>

                            </label>


                        </div>

                    </section>

                    <div class="category-create-side">

                        <section class="form-section">

                            <div class="form-section-header">

                                <h2>
                                    Imagen
                                </h2>

                                <p>
                                    Selecciona la imagen que representará
                                    esta categoría en el catálogo.
                                </p>

                            </div>


                            <div class="form-group">

                                <label class="form-label" for="image">
                                    Imagen de la categoría
                                </label>

                                <input class="form-input" id="image" name="image" type="file" accept="
                            image/jpeg,
                            image/png,
                            image/webp
                        " required>

                                <p class="form-hint">
                                    JPG, PNG o WEBP.
                                    Tamaño máximo: 5 MB.
                                </p>

                            </div>

                        </section>

                    </div>

                </div>

                <div class="form-actions category-create-actions">

                    <button class="admin-button" type="submit">
                        CREAR CATEGORÍA
                    </button>


                    <a class="
                admin-button
                admin-button-outline
            " href="<?= e(APP_BASE_URL) ?>/admin/categories/">
                        CANCELAR
                    </a>

                </div>


            </form>


        </main>


    </div>


</body>

</html>