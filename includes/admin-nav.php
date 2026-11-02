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
        'promotions' => ['Promociones', '/admin/promotions/'],
        'cash' => ['Caja', '/admin/cash/'],
        'finance' => ['Finanzas', '/admin/finance/'],
        'workshop' => ['Pedidos al taller', '/admin/workshop/'],
        'users' => ['Usuarios', '/admin/users/'],
        'backups' => ['Respaldos', '/admin/backups/'],
        'settings' => ['Configuración', '/admin/settings/'],
    ];
}

/**
 * Small line icons (24x24, stroke = currentColor) for the menu and the
 * dashboard cards. Unknown names return an empty string.
 */
function admin_icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'products' => '<path d="M3 16.5c0-1 .8-1.8 1.8-1.8h5.4c1 0 1.9-.4 2.5-1.1l3.6-4.1c.6-.7 1.6-.8 2.3-.2l1.9 1.6c.9.8 1.5 1.9 1.5 3.1v2.5c0 .8-.7 1.5-1.5 1.5H4.5c-.8 0-1.5-.7-1.5-1.5z"/><path d="M8 14.7v-3"/><path d="M3 18h18"/>',
        'categories' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'inventory' => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>',
        'sales' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'promotions' => '<path d="M12 2v4"/><path d="M4.9 4.9 7.8 7.8"/><path d="M19.1 4.9l-2.9 2.9"/><circle cx="12" cy="14" r="7"/><path d="m9.5 16.5 5-5"/><circle cx="9.8" cy="11.8" r=".6"/><circle cx="14.2" cy="16.2" r=".6"/>',
        'cash' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'finance' => '<path d="M3 3v18h18"/><path d="m7 14 4-4 3 3 6-6"/><path d="M16 7h4v4"/>',
        'workshop' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-.6-.6-2.4z"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
        'backups' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'pairs' => '<path d="M4 20h16"/><path d="M6 16V9"/><path d="M10 16V5"/><path d="M14 16v-6"/><path d="M18 16V8"/>',
        'value' => '<path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'alert' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'empty' => '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
    ];

    if (!isset($paths[$name])) {
        return '';
    }

    return '<svg class="admin-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" ' .
        'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' .
        $paths[$name] . '</svg>';
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
                        <?= admin_icon($key) ?><span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
                <a href="<?= e($base) ?>/" target="_blank" rel="noopener noreferrer">
                    <?= admin_icon('external') ?><span>Ver catálogo</span>
                </a>
            </nav>
            <?php if (function_exists('license_status') && !license_status()['valid']): ?>
                <a class="admin-license-badge" href="<?= e($base) ?>/admin/settings/#licencia">
                    <?= e(license_status()['message']) ?>
                </a>
            <?php endif; ?>
            <?php if (defined('APP_DEMO_MODE') && APP_DEMO_MODE): ?>
                <div class="admin-demo-badge">Modo demo · solo lectura</div>
                <?php if (isset($_GET['demo_readonly'])): ?>
                    <div class="admin-demo-toast" role="alert">
                        Esto es una demo: puedes ver todo, pero no se guardan cambios.
                    </div>
                <?php endif; ?>
                <script>
                    // Demo: lock every form except logout and "Actualizar base de datos".
                    document.addEventListener('DOMContentLoaded', function () {
                        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function (form) {
                            if ((form.getAttribute('action') || '').indexOf('logout.php') !== -1 ||
                                form.querySelector('input[name="action"][value="migrate"]')) {
                                return;
                            }

                            form.classList.add('admin-demo-locked');
                            form.querySelectorAll('input, select, textarea, button').forEach(function (field) {
                                if (field.type !== 'hidden') {
                                    field.disabled = true;
                                }
                            });
                            form.addEventListener('submit', function (event) {
                                event.preventDefault();
                            });
                        });
                    });
                </script>
            <?php endif; ?>
            <script src="<?= e($base) ?>/admin/admin.js?v=<?= $jsVersion ?>" defer></script>
    <?php
}
