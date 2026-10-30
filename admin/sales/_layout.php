<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Sales Pages Layout
|--------------------------------------------------------------------------
|
| Shared header + sidebar for the sales section.
| Expects $pageTitle to be set before including.
|
*/

function render_sales_layout_start(string $pageTitle, string $active = 'sales'): void
{
    $base = APP_BASE_URL;
    ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?= e($pageTitle) ?> | Administración <?= e(business_name()) ?>
    </title>
    <link href="<?= e(business_favicon_url()) ?>" rel="icon" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?= e($base) ?>/admin/admin.css?v=<?= (int) @filemtime(__DIR__ . '/../admin.css') ?>">
</head>

<body>
    <header class="admin-header">
        <a href="<?= e($base) ?>/admin/"> <img class="admin-logo" src="<?= e(business_logo_url()) ?>"
                alt="<?= e(business_name()) ?>"> </a>
        <div class="admin-user">
            <span> <?= e($_SESSION['admin_name'] ?? '') ?> · <?= e(admin_role_label()) ?> </span>
            <form method="post" action="<?= e($base) ?>/admin/logout.php">
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
            <?php render_admin_nav($active); ?>
        </aside>
        <main class="admin-content">
<?php
}

function render_sales_layout_end(): void
{
    ?>
        </main>
    </div>
</body>

</html>
<?php
}


/*
|--------------------------------------------------------------------------
| Missing Migration Notice
|--------------------------------------------------------------------------
*/

function render_sales_migration_notice(): void
{
    ?>
    <section class="empty-state">
        <h2>
            Falta crear las tablas de ventas
        </h2>
        <p>
            En <a href="<?= e(APP_BASE_URL) ?>/admin/settings/">Configuración</a> pulsa
            <strong>ACTUALIZAR BASE DE DATOS</strong> (solo el dueño puede hacerlo). Luego recarga esta página.
        </p>
    </section>
<?php
}
