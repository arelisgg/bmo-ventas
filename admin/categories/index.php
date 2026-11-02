<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

require_admin();


$statement = $pdo->query(
    'SELECT
        c.id,
        c.name,
        c.slug,
        c.image,
        c.is_active,
        c.is_coming_soon,
        c.sort_order,
        (
            SELECT COUNT(*)
            FROM products p
            WHERE p.category_id = c.id
        ) AS product_count,
        (
            SELECT COALESCE(SUM(pv.stock), 0)
            FROM product_variants pv
            INNER JOIN products p
                ON p.id = pv.product_id
            WHERE
                p.category_id = c.id
                AND pv.stock > 0
        ) AS stock_units,
        (
            SELECT COUNT(DISTINCT pv.product_id)
            FROM product_variants pv
            INNER JOIN products p
                ON p.id = pv.product_id
            WHERE
                p.category_id = c.id
                AND pv.stock > 0
        ) AS models_in_stock
     FROM categories c
     ORDER BY
        c.sort_order ASC,
        c.name ASC'
);

$categories = $statement->fetchAll();

?>
<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Categorías | Administración <?= e(business_name()) ?>
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

            <span>
                <?= e($_SESSION['admin_name']) ?>
            </span>

            <form method="post" action="<?= e(APP_BASE_URL) ?>/admin/logout.php">

                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

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
            <?php render_admin_nav('categories'); ?>
        </aside>


        <main class="admin-content">

            <div class="page-header">

                <div>

                    <span class="page-eyebrow">
                        CATÁLOGO
                    </span>

                    <h1 class="page-title">
                        Categorías
                    </h1>

                    <p class="page-description">
                        Manage catalog categories,
                        images and visibility.
                    </p>

                </div>

                <a class="admin-button" href="<?= e(APP_BASE_URL) ?>/admin/categories/create.php">
                    + AGREGAR CATEGORÍA
                </a>

            </div>


            <?php if (isset($_GET['created'])): ?>

                <div class="
                    admin-notice
                    admin-notice-success
                ">
                    Categoría creada correctamente.
                </div>

            <?php endif; ?>


            <?php if (isset($_GET['updated'])): ?>

                <div class="
                    admin-notice
                    admin-notice-success
                ">
                    Categoría actualizada correctamente.
                </div>

            <?php endif; ?>


            <?php if (isset($_GET['status'])): ?>

                <div class="
                    admin-notice
                    admin-notice-success
                ">
                    Estado de la categoría actualizado.
                </div>

            <?php endif; ?>


            <div class="admin-table-wrapper">

                <table class="admin-table">

                    <thead>

                        <tr>

                            <th>
                                Image
                            </th>

                            <th>
                                Category
                            </th>

                            <th>
                                Modelos
                            </th>

                            <th>
                                En stock
                            </th>

                            <th>
                                Order
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Coming Soon
                            </th>

                            <th>
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach (
                            $categories
                            as
                            $category
                        ): ?>

                            <tr>

                                <td>

                                    <?php if (
                                        !empty(
                                        $category['image']
                                    )
                                    ): ?>

                                        <img class="product-table-image" src="../../<?= e(
                                            $category['image']
                                        ) ?>" alt="<?= e(
                                             $category['name']
                                         ) ?>">

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <div class="product-name">

                                        <?= e(
                                            $category['name']
                                        ) ?>

                                    </div>

                                    <div class="product-code">

                                        <?= e(
                                            $category['slug']
                                        ) ?>

                                    </div>

                                </td>


                                <td>

                                    <?= (int) 
                                        $category[
                                            'product_count'
                                        ]
                                        ?>

                                </td>


                                <td>

                                    <div class="product-name">

                                        <?= (int) $category['stock_units'] ?>
                                        <?= (int) $category['stock_units'] === 1 ? 'unidad' : 'unidades' ?>

                                    </div>

                                    <div class="product-code">

                                        <?= (int) $category['models_in_stock'] ?>
                                        <?= (int) $category['models_in_stock'] === 1 ? 'modelo con stock' : 'modelos con stock' ?>

                                    </div>

                                </td>


                                <td>

                                    <?= (int) 
                                        $category[
                                            'sort_order'
                                        ]
                                        ?>

                                </td>


                                <td>

                                    <?php if (
                                        (int) 
                                        $category['is_active']
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

                                </td>


                                <td>

                                    <?= (
                                        (int) 
                                        $category[
                                            'is_coming_soon'
                                        ]
                                        === 1
                                    )
                                        ? 'Yes'
                                        : 'No'
                                        ?>

                                </td>


                                <td>

                                    <div class="product-row-actions">

                                        <a class="table-action-link" href="<?= e(
                                            APP_BASE_URL
                                        ) ?>/admin/categories/edit.php?id=<?= (int) 
                                             $category['id']
                                             ?>">
                                            Editar
                                        </a>


                                        <form class="inline-form" method="post" action="<?= e(
                                            APP_BASE_URL
                                        ) ?>/admin/categories/toggle-status.php">

                                            <input type="hidden" name="csrf_token" value="<?= e(
                                                csrf_token()
                                            ) ?>">

                                            <input type="hidden" name="category_id" value="<?= (int) 
                                                $category['id']
                                                ?>">

                                            <button class="table-action-link" type="submit">

                                                <?= (
                                                    (int) 
                                                    $category[
                                                        'is_active'
                                                    ]
                                                    === 1
                                                )
                                                    ? 'Deactivate'
                                                    : 'Activate'
                                                    ?>

                                            </button>

                                        </form>


                                        <form class="inline-form" method="post" action="<?= e(
                                            APP_BASE_URL
                                        ) ?>/admin/categories/toggle-coming-soon.php">

                                            <input type="hidden" name="csrf_token" value="<?= e(
                                                csrf_token()
                                            ) ?>">

                                            <input type="hidden" name="category_id" value="<?= (int) 
                                                $category['id']
                                                ?>">

                                            <button class="table-action-link" type="submit">

                                                <?= (
                                                    (int) 
                                                    $category[
                                                        'is_coming_soon'
                                                    ]
                                                    === 1
                                                )
                                                    ? 'Publish'
                                                    : 'Coming Soon'
                                                    ?>

                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </main>

    </div>

</body>

</html>