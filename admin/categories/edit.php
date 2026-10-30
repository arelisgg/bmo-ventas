<?php declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/category-functions.php';
require_admin();
/*
|--------------------------------------------------------------------------
| Category ID
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categoryId = filter_var(
        $_POST['category_id'] ?? null,
        FILTER_VALIDATE_INT
    );
} else {
    $categoryId = filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );
}
if (!$categoryId) {
    http_response_code(404);
    exit('Categoría no encontrada.');
}
/*
|--------------------------------------------------------------------------
| Load Category
|--------------------------------------------------------------------------
*/
$categoryStatement = $pdo->prepare(
    'SELECT
        id,
        name,
        slug,
        image,
        is_active,
        is_coming_soon,
        sort_order
     FROM categories
     WHERE id = :id
     LIMIT 1'
);
$categoryStatement->execute([
    'id' => $categoryId,
]);
$category = $categoryStatement->fetch();
if (!$category) {
    http_response_code(404);
    exit('Categoría no encontrada.');
}
/*
|--------------------------------------------------------------------------
| Form State
|--------------------------------------------------------------------------
*/
$isPost =
    $_SERVER['REQUEST_METHOD'] === 'POST';
$error = '';
$name = trim(
    (string) (
        $isPost
        ? ($_POST['name'] ?? '')
        : $category['name']
    )
);
$sortOrder = trim(
    (string) (
        $isPost
        ? ($_POST['sort_order'] ?? '0')
        : $category['sort_order']
    )
);
$isActive =
    $isPost
    ? isset($_POST['is_active'])
    : (int) $category['is_active'] === 1;
$isComingSoon =
    $isPost
    ? isset($_POST['is_coming_soon'])
    : (int) $category['is_coming_soon'] === 1;
/*
|--------------------------------------------------------------------------
| Update Category
|--------------------------------------------------------------------------
*/
if ($isPost) {    /*
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
    | Name
    |--------------------------------------------------------------------------
    */ elseif ($name === '') {
        $error =
            'El nombre de la categoría es obligatorio.';
    }
    /*
    |--------------------------------------------------------------------------
    | Sort Order
    |--------------------------------------------------------------------------
    */ elseif (
        filter_var(
            $sortOrder,
            FILTER_VALIDATE_INT
        ) === false
    ) {
        $error =
            'El orden debe ser un número válido.';
    } elseif ((int) $sortOrder < 0) {
        $error =
            'El orden no puede ser negativo.';
    }
    /*
    |--------------------------------------------------------------------------
    | Optional New Image
    |--------------------------------------------------------------------------
    */
    $oldImagePath =
        $category['image'];
    $newImagePath = null;
    $finalImagePath =
        $oldImagePath;
    $uploadedFile =
        $_FILES['image']
        ?? null;
    if (
        $error === '' &&
        is_array($uploadedFile) &&
        isset($uploadedFile['error']) &&
        (int) $uploadedFile['error']
        !== UPLOAD_ERR_NO_FILE
    ) {
        try {
            $newImagePath =
                upload_category_image(
                    $uploadedFile
                );
            $finalImagePath =
                $newImagePath;
        } catch (
            RuntimeException $exception
        ) {
            $error =
                $exception->getMessage();
        }
    }
    /*
    |--------------------------------------------------------------------------
    | Update Database
    |--------------------------------------------------------------------------
    */
    if ($error === '') {
        try {            /*
|--------------------------------------------------------------------------
| Generate Slug
|--------------------------------------------------------------------------
*/
            $slug =
                generate_unique_category_slug(
                    $pdo,
                    $name,
                    $categoryId
                );
            /*
            |--------------------------------------------------------------------------
            | Update Category
            |--------------------------------------------------------------------------
            */
            $updateStatement = $pdo->prepare(
                'UPDATE categories
                 SET
                    name = :name,
                    slug = :slug,
                    image = :image,
                    is_active = :is_active,
                    is_coming_soon = :is_coming_soon,
                    sort_order = :sort_order
                 WHERE id = :id'
            );
            $updateStatement->execute([
                'name' =>
                    $name,
                'slug' =>
                    $slug,
                'image' =>
                    $finalImagePath,
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
                'id' =>
                    $categoryId,
            ]);
            /*
            |--------------------------------------------------------------------------
            | Delete Previous Managed Image
            |--------------------------------------------------------------------------
            |
            | delete_category_image() only removes files inside:
            |
            | uploads/categories/
            |
            | Files inside assets/ remain untouched.
            |
            */
            if (
                $newImagePath !== null &&
                $oldImagePath !== null &&
                $newImagePath !== $oldImagePath
            ) {
                delete_category_image(
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
                '/admin/categories/?updated=1'
            );
            exit;
        } catch (
            Throwable $exception
        ) {            /*
  |--------------------------------------------------------------------------
  | Remove Newly Uploaded Image If Database Update Failed
  |--------------------------------------------------------------------------
  */
            if ($newImagePath !== null) {
                delete_category_image(
                    $newImagePath
                );
            }
            error_log(
                $exception->getMessage()
            );
            $error =
                'No se pudo actualizar la categoría.';
        }
    }
} ?>
<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Editar categoría | Administración <?= e(business_name()) ?>
    </title>

    <link href="<?= e(business_favicon_url()) ?>" rel="icon">

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

            <span>
                <?= e(
                    $_SESSION['admin_name']
                ) ?>
            </span>

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

            <p class="sidebar-title">
                Administración
            </p>

            <?php require_once __DIR__ . '/../../includes/admin-nav.php'; ?>
            <?php render_admin_nav('categories'); ?>

        </aside>



        <main class="admin-content">


            <div class="page-header">

                <div>

                    <span class="page-eyebrow">
                        CATÁLOGO
                    </span>

                    <h1 class="page-title">
                        Editar categoría
                    </h1>

                    <p class="page-description">
                        Actualiza la información, imagen,
                        visibilidad y orden de la categoría.
                    </p>

                </div>

                <a class="
                    admin-button
                    admin-button-outline
                " href="<?= e(APP_BASE_URL) ?>/admin/categories/">
                    ← CATEGORÍAS
                </a>

            </div>



            <?php if ($error !== ''): ?>

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

                <input type="hidden" name="category_id" value="<?= (int) $categoryId ?>">


                <div class="category-create-grid">

                    <!-- Left column -->

                    <section class="form-section">

                        <div class="form-section-header">

                            <h2>
                                Información de la categoría
                            </h2>

                            <p>
                                Actualiza el nombre y el orden
                                de visualización.
                            </p>

                        </div>


                        <div class="category-form-fields">


                            <div class="form-group">

                                <label class="form-label" for="name">
                                    Nombre de la categoría
                                </label>

                                <input class="form-input" id="name" name="name" type="text" value="<?= e($name) ?>"
                                    required>

                            </div>



                            <div class="form-group">

                                <label class="form-label" for="sort_order">
                                    Orden
                                </label>

                                <input class="form-input" id="sort_order" name="sort_order" type="number" min="0"
                                    step="1" value="<?= e(
                                        $sortOrder
                                    ) ?>" required>

                                <p class="form-hint">
                                    Los números menores aparecen primero.
                                </p>

                            </div>



                            <div class="form-group">

                                <label class="form-label">
                                    Slug actual
                                </label>

                                <input class="form-input" type="text" value="<?= e(
                                    $category['slug']
                                ) ?>" readonly>

                                <p class="form-hint">
                                    El slug se genera automáticamente
                                    a partir del nombre de la categoría.
                                </p>

                            </div>


                        </div>

                    </section>


                    <!-- Right column -->

                    <div class="category-create-side">

                        <section class="form-section">

                            <div class="form-section-header">

                                <h2>
                                    Visibilidad
                                </h2>

                                <p>
                                    Controla cómo aparecerá esta categoría
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
                                            Mostrar el mensaje de próximamente
                                            en lugar de productos.
                                        </small>

                                    </span>

                                </label>


                            </div>

                        </section>

                        <section class="form-section">

                            <div class="form-section-header">

                                <h2>
                                    Imagen de la categoría
                                </h2>

                                <p>
                                    Reemplaza la imagen mostrada
                                    en el catálogo público.
                                </p>

                            </div>


                            <?php if (
                                !empty(
                                $category['image']
                            )
                            ): ?>

                                <div class="form-group">

                                    <label class="form-label">
                                        Imagen actual
                                    </label>

                                    <div class="current-image">

                                        <img src="../../<?= e(
                                            $category['image']
                                        ) ?>" alt="<?= e(
                                             $category['name']
                                         ) ?>">

                                    </div>

                                    <p class="form-hint">
                                        Ruta actual:
                                        <?= e(
                                            $category['image']
                                        ) ?>
                                    </p>

                                </div>

                            <?php endif; ?>


                            <div class="form-group">

                                <label class="form-label" for="image">
                                    Reemplazar imagen
                                </label>

                                <input class="form-input" id="image" name="image" type="file" accept="
                                    image/jpeg,
                                    image/png,
                                    image/webp
                                ">

                                <p class="form-hint">
                                    Déjalo vacío para conservar
                                    la imagen actual.
                                    JPG, PNG o WEBP.
                                    Tamaño máximo: 5 MB.
                                </p>

                            </div>

                        </section>
                    </div>

                </div>



                <div class="form-actions category-create-actions">

                    <button class="admin-button" type="submit">
                        GUARDAR CAMBIOS
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