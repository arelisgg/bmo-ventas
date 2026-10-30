<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sales-functions.php';
require_once __DIR__ . '/../includes/backup-functions.php';

require_admin();

run_daily_backup($pdo);/*
|--------------------------------------------------------------------------
| Dashboard Statistics
|--------------------------------------------------------------------------
*/

$statisticsStatement = $pdo->query(
    'SELECT

        (
            SELECT COUNT(*)
            FROM products
        ) AS total_products,

        (
            SELECT COUNT(*)
            FROM products
            WHERE is_active = 1
        ) AS active_products,

        (
            SELECT COUNT(*)
            FROM categories
            WHERE is_active = 1
        ) AS active_categories,

        (
            SELECT COALESCE(
                SUM(stock),
                0
            )
            FROM product_variants
        ) AS total_stock'
);

$statistics =
    $statisticsStatement->fetch();

/*
|--------------------------------------------------------------------------
| Sales Statistics
|--------------------------------------------------------------------------
*/

$salesReady = sales_tables_exist($pdo);

$salesStatistics = [
    'today_total' => 0,
    'today_count' => 0,
    'month_total' => 0,
    'month_count' => 0,
    'pending_total' => 0,
];

if ($salesReady) {
    $salesStatistics = $pdo->query(
        'SELECT
            COALESCE(SUM(CASE WHEN sold_at >= CURDATE() THEN total ELSE 0 END), 0) AS today_total,
            COALESCE(SUM(CASE WHEN sold_at >= CURDATE() THEN 1 ELSE 0 END), 0) AS today_count,
            COALESCE(SUM(CASE WHEN sold_at >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\') THEN total ELSE 0 END), 0) AS month_total,
            COALESCE(SUM(CASE WHEN sold_at >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\') THEN 1 ELSE 0 END), 0) AS month_count,
            COALESCE(SUM(CASE WHEN status = \'pending\' THEN total ELSE 0 END), 0) AS pending_total
         FROM sales
         WHERE status != \'cancelled\' AND doc_type = \'invoice\''
    )->fetch() ?: $salesStatistics;
}
/*
|--------------------------------------------------------------------------
| Product Stock Summary
|--------------------------------------------------------------------------
*/

$productStockStatement = $pdo->query(
    'SELECT
        p.id,
        p.code,
        p.name,
        p.main_image,
        p.is_active,
        c.name AS category_name,

        COALESCE(
            SUM(pv.stock),
            0
        ) AS total_stock

     FROM products p

     INNER JOIN categories c
        ON c.id = p.category_id

     LEFT JOIN product_variants pv
        ON pv.product_id = p.id

     GROUP BY
        p.id,
        p.code,
        p.name,
        p.main_image,
        p.is_active,
        c.name'
);

$productStockRows =
    $productStockStatement->fetchAll();/*
|--------------------------------------------------------------------------
| Calculate Low Stock And Out Of Stock Products
|--------------------------------------------------------------------------
*/

$lowStockProducts = [];
$outOfStockProducts = [];

foreach (
    $productStockRows as $product
) {

    if (
        (int) $product['is_active']
        !== 1
    ) {
        continue;
    }

    $stock =
        (int) $product['total_stock'];
    if ($stock === 0) {

        $outOfStockProducts[] =
            $product;

        continue;
    }
    if ($stock <= 5) {

        $lowStockProducts[] =
            $product;
    }
}/*
|--------------------------------------------------------------------------
| Sort Low Stock
|--------------------------------------------------------------------------
*/

usort(
    $lowStockProducts,
    static function (array $a, array $b): int {

        return
            (int) $a['total_stock']
            <=>
            (int) $b['total_stock'];
    }
);/*
|--------------------------------------------------------------------------
| Recent Products
|--------------------------------------------------------------------------
*/

$recentProductsStatement =
    $pdo->query(
        'SELECT
            p.id,
            p.code,
            p.name,
            p.main_image,
            p.is_active,
            p.created_at,
            c.name AS category_name

         FROM products p

         INNER JOIN categories c
            ON c.id = p.category_id

         ORDER BY
            p.created_at DESC,
            p.id DESC

         LIMIT 5'
    );

$recentProducts =
    $recentProductsStatement->fetchAll();/*
|--------------------------------------------------------------------------
| Low Stock Variants
|--------------------------------------------------------------------------
*/

$lowStockVariantsStatement =
    $pdo->query(
        'SELECT
            pv.id,
            pv.color_name,
            pv.size,
            pv.stock,

            p.id AS product_id,
            p.code,
            p.name

         FROM product_variants pv

         INNER JOIN products p
            ON p.id = pv.product_id

         WHERE
            p.is_active = 1
            AND pv.stock > 0
            AND pv.stock <= 2

         ORDER BY
            pv.stock ASC,
            p.name ASC,
            pv.color_name ASC,
            CAST(pv.size AS UNSIGNED) ASC

         LIMIT 10'
    );

$lowStockVariants =
    $lowStockVariantsStatement->fetchAll();

?>
<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Panel | Administración <?= e(business_name()) ?>
    </title>

    <link href="<?= e(business_favicon_url()) ?>" rel="icon">

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="admin.css?v=<?= (int) @filemtime(__DIR__ . '/admin.css') ?>">

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
            <?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>
            <?php render_admin_nav('dashboard'); ?>

        </aside>
        <main class="admin-content"> <!-- =====================================================
             PAGE HEADER
             ===================================================== -->

            <div class="page-header">

                <div>

                    <span class="page-eyebrow">
                        RESUMEN
                    </span>

                    <h1 class="page-title">
                        Panel
                    </h1>

                    <p class="page-description">
                        Resumen general del catálogo y el inventario.
                    </p>

                </div> <a class="admin-button" href="<?= e(APP_BASE_URL) ?>/admin/products/create.php">
                    + AGREGAR PRODUCTO
                </a>

            </div>
            <!-- =====================================================
             STATISTICS
             ===================================================== -->

            <div class="dashboard-stat-grid">
<?php if ($salesReady && admin_can('sales')): ?>
                <article class="dashboard-stat-card">
                    <span class="dashboard-stat-label">
                        Ventas de hoy
                    </span>
                    <strong class="dashboard-stat-value">
                        <?= e(format_money($salesStatistics['today_total'])) ?>
                    </strong>
                    <span class="dashboard-stat-note">
                        <?= (int) $salesStatistics['today_count'] ?> venta(s)
                    </span>
                </article>
                <article class="dashboard-stat-card">
                    <span class="dashboard-stat-label">
                        Ventas del mes
                    </span>
                    <strong class="dashboard-stat-value">
                        <?= e(format_money($salesStatistics['month_total'])) ?>
                    </strong>
                    <a href="<?= e(APP_BASE_URL) ?>/admin/sales/">
                        <?= (int) $salesStatistics['month_count'] ?> venta(s) · Ver ventas →
                    </a>
                </article>
<?php if ((float) $salesStatistics['pending_total'] > 0): ?>
                <article class="dashboard-stat-card">
                    <span class="dashboard-stat-label">
                        Pendiente de cobro
                    </span>
                    <strong class="dashboard-stat-value">
                        <?= e(format_money($salesStatistics['pending_total'])) ?>
                    </strong>
                    <a href="<?= e(APP_BASE_URL) ?>/admin/sales/?status=pending&amp;from=2000-01-01">
                        Ver pendientes →
                    </a>
                </article>
<?php endif; ?>
<?php endif; ?>
                <article class="dashboard-stat-card">

                    <span class="dashboard-stat-label">
                        Productos
                    </span>

                    <strong class="dashboard-stat-value">

                        <?= (int) 
                            $statistics[
                                'total_products'
                            ]
                            ?>

                    </strong>

                    <a href="<?= e(APP_BASE_URL) ?>/admin/products/">
                        Administrar productos →
                    </a>

                </article>
                <article class="dashboard-stat-card">

                    <span class="dashboard-stat-label">
                        Productos activos
                    </span>

                    <strong class="dashboard-stat-value">

                        <?= (int) 
                            $statistics[
                                'active_products'
                            ]
                            ?>

                    </strong>

                    <span class="dashboard-stat-note">
                        Visibles en el catálogo
                    </span>

                </article>
                <article class="dashboard-stat-card">

                    <span class="dashboard-stat-label">
                        Stock total
                    </span>

                    <strong class="dashboard-stat-value">

                        <?= (int) 
                            $statistics[
                                'total_stock'
                            ]
                            ?>

                    </strong>

                    <a href="<?= e(APP_BASE_URL) ?>/admin/inventory/">
                        Administrar inventario →
                    </a>

                </article>
                <article class="dashboard-stat-card">

                    <span class="dashboard-stat-label">
                        Stock bajo
                    </span>

                    <strong class="dashboard-stat-value">

                        <?= count(
                            $lowStockProducts
                        ) ?>

                    </strong>

                    <span class="dashboard-stat-note">
                        Entre 1 y 5 unidades disponibles
                    </span>

                </article>
                <article class="dashboard-stat-card">

                    <span class="dashboard-stat-label">
                        Agotados
                    </span>

                    <strong class="dashboard-stat-value">

                        <?= count(
                            $outOfStockProducts
                        ) ?>

                    </strong>

                    <span class="dashboard-stat-note">
                        Productos activos sin existencias
                    </span>

                </article>
                <article class="dashboard-stat-card">

                    <span class="dashboard-stat-label">
                        Categorías
                    </span>

                    <strong class="dashboard-stat-value">

                        <?= (int) 
                            $statistics[
                                'active_categories'
                            ]
                            ?>

                    </strong>

                    <?php if (admin_can('categories')): ?>
                        <a href="<?= e(APP_BASE_URL) ?>/admin/categories/">
                            Administrar categorías →
                        </a>
                    <?php endif; ?>

                </article>
            </div>
            <!-- =====================================================
             MAIN DASHBOARD GRID
             ===================================================== -->

            <div class="dashboard-content-grid"> <!-- =================================================
                 LOW STOCK VARIANTS
                 ================================================= -->

                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">

                        <div>

                            <span class="page-eyebrow">
                                INVENTARIO
                            </span>

                            <h2>
                                Stock bajo
                            </h2>

                        </div> <a href="<?= e(APP_BASE_URL) ?>/admin/inventory/">
                            Ver inventario
                        </a>

                    </div>
                    <?php if (
                        count(
                            $lowStockVariants
                        ) > 0
                    ): ?>
                        <div class="dashboard-table-wrapper">

                            <table class="dashboard-table">

                                <thead>

                                    <tr>

                                        <th>
                                            Producto
                                        </th>

                                        <th>
                                            Color
                                        </th>

                                        <th>
                                            Talla
                                        </th>

                                        <th>
                                            Stock
                                        </th>

                                    </tr>

                                </thead>
                                <tbody> <?php foreach (
                                    $lowStockVariants
                                    as
                                    $variant
                                ): ?>
                                        <tr>
                                            <td>

                                                <a class="dashboard-product-link" href="<?= e(APP_BASE_URL) ?>/admin/products/edit.php?id=<?= (int) 
                                                      $variant[
                                                          'product_id'
                                                      ]
                                                      ?>">

                                                    <strong>

                                                        <?= e(
                                                            $variant[
                                                                'name'
                                                            ]
                                                        ) ?>

                                                    </strong>

                                                    <span>

                                                        <?= e(
                                                            $variant[
                                                                'code'
                                                            ]
                                                        ) ?>

                                                    </span>

                                                </a>

                                            </td>
                                            <td>

                                                <?= e(
                                                    $variant[
                                                        'color_name'
                                                    ]
                                                ) ?>

                                            </td>
                                            <td>

                                                <?= e(
                                                    $variant[
                                                        'size'
                                                    ]
                                                ) ?>

                                            </td>
                                            <td>

                                                <span class="
                                                stock-number
                                                stock-number-low
                                            ">

                                                    <?= (int) 
                                                        $variant[
                                                            'stock'
                                                        ]
                                                        ?>

                                                </span>

                                            </td>
                                        </tr> <?php endforeach; ?>
                                </tbody>

                            </table>

                        </div> <?php else: ?>
                        <div class="dashboard-empty">
                            No hay variantes con stock bajo.
                        </div> <?php endif; ?>
                </section>
                <!-- =================================================
                 RECENT PRODUCTS
                 ================================================= -->

                <section class="dashboard-panel">
                    <div class="dashboard-panel-header">

                        <div>

                            <span class="page-eyebrow">
                                CATÁLOGO
                            </span>

                            <h2>
                                Productos recientes
                            </h2>

                        </div> <a href="<?= e(APP_BASE_URL) ?>/admin/products/">
                            Ver todos
                        </a>

                    </div>
                    <?php if (
                        count(
                            $recentProducts
                        ) > 0
                    ): ?>
                        <div class="dashboard-recent-products"> <?php foreach (
                            $recentProducts
                            as
                            $product
                        ): ?> <a class="dashboard-recent-product" href="<?= e(APP_BASE_URL) ?>/admin/products/edit.php?id=<?= (int) 
                                $product[
                                    'id'
                                ]
                                ?>"> <?php if (
                                !empty(
                                $product[
                                    'main_image'
                                ]
                            )
                            ): ?> <img src="../<?= e(
                                  $product[
                                      'main_image'
                                  ]
                              ) ?>" alt="<?= e(
                                   $product[
                                       'name'
                                   ]
                               ) ?>"> <?php endif; ?>
                                    <div>

                                        <span>

                                            <?= e(
                                                $product[
                                                    'code'
                                                ]
                                            ) ?>

                                        </span> <strong>

                                            <?= e(
                                                $product[
                                                    'name'
                                                ]
                                            ) ?>

                                        </strong> <small>

                                            <?= e(
                                                $product[
                                                    'category_name'
                                                ]
                                            ) ?>

                                        </small>

                                    </div> <?php if (
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
                                        </span> <?php endif; ?>
                                </a> <?php endforeach; ?> </div> <?php else: ?>
                        <div class="dashboard-empty">
                            Aún no hay productos.
                        </div> <?php endif; ?>
                </section>
            </div>
            <!-- =====================================================
             OUT OF STOCK
             ===================================================== -->

            <?php if (
                count(
                    $outOfStockProducts
                ) > 0
            ): ?>
                <section class="
                    dashboard-panel
                    dashboard-out-stock
                ">
                    <div class="dashboard-panel-header">

                        <div>

                            <span class="page-eyebrow">
                                ATENCIÓN
                            </span>

                            <h2>
                                Productos agotados
                            </h2>

                        </div> <a href="<?= e(APP_BASE_URL) ?>/admin/inventory/">
                            Actualizar inventario
                        </a>

                    </div>
                    <div class="dashboard-out-stock-grid"> <?php foreach (
                        array_slice(
                            $outOfStockProducts,
                            0,
                            6
                        )
                        as
                        $product
                    ): ?> <a class="out-stock-product" href="<?= e(APP_BASE_URL) ?>/admin/products/edit.php?id=<?= (int) 
                            $product[
                                'id'
                            ]
                            ?>"> <?php if (
                            !empty(
                            $product[
                                'main_image'
                            ]
                        )
                        ): ?> <img src="../<?= e(
                              $product[
                                  'main_image'
                              ]
                          ) ?>" alt="<?= e(
                               $product[
                                   'name'
                               ]
                           ) ?>"> <?php endif; ?>
                                <div>

                                    <span>

                                        <?= e(
                                            $product[
                                                'code'
                                            ]
                                        ) ?>

                                    </span> <strong>

                                        <?= e(
                                            $product[
                                                'name'
                                            ]
                                        ) ?>

                                    </strong> <small>
                                        Agotado
                                    </small>

                                </div>
                            </a> <?php endforeach; ?> </div>
                </section> <?php endif; ?>
            <!-- =====================================================
             QUICK ACTIONS
             ===================================================== -->

            <section class="dashboard-panel">
                <div class="dashboard-panel-header">

                    <div>

                        <span class="page-eyebrow">
                            ACCESOS RÁPIDOS
                        </span>

                        <h2>
                            Acciones rápidas
                        </h2>

                    </div>

                </div>
                <div class="dashboard-quick-actions"> <a class="dashboard-action-card"
                        href="<?= e(APP_BASE_URL) ?>/admin/products/create.php">

                        <span>
                            01
                        </span>

                        <strong>
                            Agregar producto
                        </strong>

                        <small>
                            Crear un nuevo producto para el catálogo.
                        </small>

                    </a>
                    <a class="dashboard-action-card" href="<?= e(APP_BASE_URL) ?>/admin/inventory/">

                        <span>
                            02
                        </span>

                        <strong>
                            Inventario
                        </strong>

                        <small>
                            Actualizar existencias por talla y color.
                        </small>

                    </a>
                    <?php if (admin_can('categories')): ?>
                    <a class="dashboard-action-card" href="<?= e(APP_BASE_URL) ?>/admin/categories/">

                        <span>
                            03
                        </span>

                        <strong>
                            Categorías
                        </strong>

                        <small>
                            Administrar las categorías del catálogo.
                        </small>

                    </a>
                    <?php endif; ?>
                    <a class="dashboard-action-card" href="<?= e(APP_BASE_URL) ?>/" target="_blank"
                        rel="noopener noreferrer">

                        <span>
                            04
                        </span>

                        <strong>
                            Ver catálogo
                        </strong>

                        <small>
                            Abrir el sitio público de <?= e(business_name()) ?>.
                        </small>

                    </a>
                </div>
            </section>
        </main>
    </div>
</body>

</html>