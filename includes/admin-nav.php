<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/functions.php';


/*
|--------------------------------------------------------------------------
| Admin Navigation
|--------------------------------------------------------------------------
|
| One menu for every admin page. On phones the sidebar becomes a
| scrollable bar under the header (see admin.css) and admin.js turns
| tables into cards.
|
*/

function admin_nav_items(): array
{
    return [
        'dashboard' => ['Panel', '/admin/'],
        'products' => ['Productos', '/admin/products/'],
        'categories' => ['Categorías', '/admin/categories/'],
        'inventory' => ['Inventario', '/admin/inventory/'],
        'sales' => ['Ventas', '/admin/sales/'],
        'finance' => ['Finanzas', '/admin/finance/'],
        'workshop' => ['Pedidos al taller', '/admin/workshop/'],
        'users' => ['Usuarios', '/admin/users/'],
        'backups' => ['Respaldos', '/admin/backups/'],
        'settings' => ['Configuración', '/admin/settings/'],
    ];
}

function render_admin_nav(string $active = ''): void
{
    $base = APP_BASE_URL;
    $jsVersion = (int) @filemtime(__DIR__ . '/../admin/admin.js');
    ?>
            <nav class="sidebar-nav">
                <?php foreach (admin_nav_items() as $key => [$label, $path]): ?>
                    <?php if (function_exists('admin_can') && !admin_can($key)) continue; ?>
                    <a class="<?= $key === $active ? 'active' : '' ?>" href="<?= e($base . $path) ?>">
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
                <a href="<?= e($base) ?>/" target="_blank" rel="noopener noreferrer">
                    Ver catálogo ↗
                </a>
            </nav>
            <?php if (function_exists('license_status') && !license_status()['valid']): ?>
                <a class="admin-license-badge" href="<?= e($base) ?>/admin/settings/#licencia">
                    <?= e(license_status()['message']) ?>
                </a>
            <?php endif; ?>
            <?php if (defined('APP_DEMO_MODE') && APP_DEMO_MODE): ?>
                <div class="admin-demo-badge">Modo demo · datos de ejemplo</div>
            <?php endif; ?>
            <script src="<?= e($base) ?>/admin/admin.js?v=<?= $jsVersion ?>" defer></script>
    <?php
}
