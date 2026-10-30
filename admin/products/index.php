<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_admin();
/*
|--------------------------------------------------------------------------
| Load Productos
|--------------------------------------------------------------------------
*/
$statement =
    $pdo->query(
        'SELECT
            p.id,
            p.code,
            p.name,
            p.price,
            p.main_image,
            p.is_active,
            p.is_featured,
            p.is_new,
            p.created_at,            c.name AS category_name,            COUNT(pv.id) AS variant_count,            COALESCE(
                SUM(pv.stock),
                0
            ) AS total_stock         FROM products p         INNER JOIN categories c
            ON c.id = p.category_id         LEFT JOIN product_variants pv
            ON pv.product_id = p.id         GROUP BY
            p.id,
            p.code,
            p.name,
            p.price,
            p.main_image,
            p.is_active,
            p.is_featured,
            p.is_new,
            p.created_at,
            c.name         ORDER BY
            p.created_at DESC,
            p.id DESC'
    );
$products =
    $statement->fetchAll(); ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Productos | Administración <?= e(business_name()) ?>
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
    <header class="admin-header"> <a href="<?= e(APP_BASE_URL) ?>/admin/">
            <img class="admin-logo" src="<?= e(business_logo_url()) ?>" alt="<?= e(business_name()) ?>">
        </a>
        <div class="admin-user"> <span>
                <?= e(
                    $_SESSION[
                        'admin_name'
                    ]
                ) ?>
            </span>
            <form method="post" action="<?= e(APP_BASE_URL) ?>/admin/logout.php"> <input type="hidden" name="csrf_token"
                    value="<?= e(
                        csrf_token()
                    ) ?>"> <button class="logout-button" type="submit">
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
                        Productos
                    </h1>
                    <p class="page-description">
                        Administra productos, inventario,
                        visibilidad e información
                        del producto.
                    </p>
                </div>
                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <a class="admin-button admin-button-outline" href="<?= e(APP_BASE_URL) ?>/admin/products/catalog.php" target="_blank">
                        CATÁLOGO MINORISTA (PDF)
                    </a>
                    <a class="admin-button" href="<?= e(APP_BASE_URL) ?>/admin/products/create.php">
                        + AGREGAR PRODUCTO
                    </a>
                </div>
            </div>
            <?php if (
                isset(
                $_GET['created']
            )
            ): ?>
                <div class="
                    admin-notice
                    admin-notice-success
                ">
                    Productoo creado correctamente.
                </div> <?php endif; ?>
            <?php if (
                isset(
                $_GET['updated']
            )
            ): ?>
                <div class="
                    admin-notice
                    admin-notice-success
                ">
                    Productoo actualizado correctamente.
                </div> <?php endif; ?>
            <?php if (
                isset(
                $_GET['status']
            )
            ): ?>
                <div class="
                    admin-notice
                    admin-notice-success
                ">
                    Estado del producto actualizado.
                </div> <?php endif; ?>
            <?php if (
                isset(
                $_GET['deleted']
            )
            ): ?>
                <div class="
                    admin-notice
                    admin-notice-success
                ">
                    Productoo eliminado correctamente.
                </div> <?php endif; ?>
            <?php if (
                isset(
                $_GET[
                    'delete_error'
                ]
            )
            ): ?>
                <div class="
                    admin-notice
                    admin-notice-error
                ">
                    No se pudo eliminar el producto.
                </div> <?php endif; ?>
            <?php if (
                count(
                    $products
                )
                === 0
            ): ?>
                <section class="empty-state">
                    <h2>
                        Aún no hay productos
                    </h2>
                    <p>
                        Crea tu primer producto
                        para comenzar a construir el catálogo.
                    </p> <a class="admin-button" href="<?= e(APP_BASE_URL) ?>/admin/products/create.php">
                        AGREGAR PRIMER PRODUCTO
                    </a>
                </section>
            <?php else: ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>
                                    Imagen
                                </th>
                                <th>
                                    Producto
                                </th>
                                <th>
                                    Categoría
                                </th>
                                <th>
                                    Precio
                                </th>
                                <th>
                                    Variantes
                                </th>
                                <th>
                                    Stock
                                </th>
                                <th>
                                    Estado
                                </th>
                                <th>
                                    Acciones
                                </th>
                            </tr>
                        </thead>
                        <tbody> <?php foreach (
                            $products
                            as
                            $product
                        ): ?>
                                <tr>
                                    <td> <?php if (
                                        !empty(
                                        $product[
                                            'main_image'
                                        ]
                                    )
                                    ): ?> <img class="product-table-image" src="../../<?= e(
                                          $product[
                                              'main_image'
                                          ]
                                      ) ?>" alt="<?= e(
                                           $product[
                                               'name'
                                           ]
                                       ) ?>"> <?php endif; ?> </td>
                                    <td>
                                        <div class="product-name"> <?= e(
                                            $product[
                                                'name'
                                            ]
                                        ) ?> </div>
                                        <div class="product-code"> <?= e(
                                            $product[
                                                'code'
                                            ]
                                        ) ?> </div>
                                    </td>
                                    <td> <?= e(
                                        $product[
                                            'category_name'
                                        ]
                                    ) ?> </td>
                                    <td> <?php if (
                                        $product['price']
                                        !== null
                                    ): ?> <?= e(currency_symbol()) ?><?= number_format(
                                          (float) 
                                          $product[
                                              'price'
                                          ],
                                          2
                                      ) ?>         <?php else: ?> Consultar <?php endif; ?> </td>
                                    <td> <?= (int) 
                                        $product[
                                            'variant_count'
                                        ]
                                        ?> </td>
                                    <td> <?= (int) 
                                        $product[
                                            'total_stock'
                                        ]
                                        ?> </td>
                                    <td> <?php if (
                                        (int) 
                                        $product[
                                            'is_active'
                                        ]
                                        === 1
                                    ): ?> <span class="
                                            status-badge
                                            status-active
                                        ">
                                                Activo
                                            </span> <?php else: ?> <span class="
                                            status-badge
                                            status-inactive
                                        ">
                                                Inactivo
                                            </span> <?php endif; ?> </td>
                                    <td>
                                        <div class="product-row-actions">

                                            <a class="table-action-link" href="<?= e(APP_BASE_URL) ?>/admin/products/edit.php?id=<?= (int) 
                                                  $product['id']
                                                  ?>">
                                                Editar
                                            </a>


                                            <a class="table-action-link" href="<?= e(APP_BASE_URL) ?>/admin/products/images.php?id=<?= (int) 
                                                  $product['id']
                                                  ?>">
                                                Imágenes
                                            </a>


                                            <form class="inline-form" method="post"
                                                action="<?= e(APP_BASE_URL) ?>/admin/products/toggle-status.php">

                                                <input type="hidden" name="csrf_token" value="<?= e(
                                                    csrf_token()
                                                ) ?>">

                                                <input type="hidden" name="product_id" value="<?= (int) 
                                                    $product['id']
                                                    ?>">

                                                <button class="table-action-link" type="submit">

                                                    <?= (
                                                        (int) 
                                                        $product['is_active']
                                                        === 1
                                                    )
                                                        ? 'Desactivar'
                                                        : 'Activar'
                                                        ?>

                                                </button>

                                            </form>


                                            <form class="inline-form" method="post"
                                                action="<?= e(APP_BASE_URL) ?>/admin/products/delete.php"
                                                onsubmit="return confirm('¿Eliminar este producto permanentemente?');">

                                                <input type="hidden" name="csrf_token" value="<?= e(
                                                    csrf_token()
                                                ) ?>">

                                                <input type="hidden" name="product_id" value="<?= (int) 
                                                    $product['id']
                                                    ?>">

                                                <button class="
                table-action-link
                table-action-danger
            " type="submit">
                                                    Eliminar
                                                </button>

                                            </form>

                                        </div>
                                    </td>
                                </tr> <?php endforeach; ?>
                        </tbody>
                    </table>
                </div> <?php endif; ?>
        </main>
    </div>
</body>

</html>