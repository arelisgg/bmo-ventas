<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/migration-functions.php';
require_once __DIR__ . '/../sales/_layout.php';

require_admin();


/*
|--------------------------------------------------------------------------
| Business Settings
|--------------------------------------------------------------------------
|
| Nombre, logo, contacto, catálogo, moneda e impuestos del negocio. Lo que
| se guarda aquí sale en el catálogo, el panel, las facturas y los PDF.
|
*/

$error = '';
$migrationError = '';


/*
|--------------------------------------------------------------------------
| Update Database
|--------------------------------------------------------------------------
|
| Aplica los archivos de database/migrations/ que falten, para que una
| actualización del sistema no necesite phpMyAdmin.
|
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'migrate') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $migrationError = 'Token de seguridad inválido. Recarga la página e intenta de nuevo.';
    } else {
        try {
            $applied = run_pending_migrations($pdo);

            header('Location: ' . APP_BASE_URL . '/admin/settings/?migrated=' . count($applied));
            exit;
        } catch (RuntimeException $exception) {
            $migrationError = $exception->getMessage();
        }
    }
}

$pendingMigrations = pending_migrations($pdo);
$tableReady = settings_table_exists($pdo);
$form = app_settings();

$textFields = [
    'business_name' => 120,
    'business_tagline' => 120,
    'business_tax_id' => 40,
    'business_address' => 255,
    'business_phone' => 40,
    'business_email' => 190,
    'whatsapp_number' => 40,
    'instagram_user' => 80,
    'facebook_url' => 255,
    'tiktok_user' => 80,
    'hero_text' => 500,
    'about_text' => 3000,
    'currency_symbol' => 6,
    'tax_label' => 20,
    'tax_rate' => 6,
    'license_key' => 4000,
];

if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'migrate') {
    foreach (array_keys($textFields) as $field) {
        $value = $_POST[$field] ?? '';
        $form[$field] = is_string($value) ? trim($value) : '';
    }

    $form['tax_enabled'] = ($_POST['tax_enabled'] ?? '0') === '1' ? '1' : '0';
    $form['prices_include_tax'] = ($_POST['prices_include_tax'] ?? '1') === '1' ? '1' : '0';

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido. Recarga la página e intenta de nuevo.';
    } elseif ($form['business_name'] === '') {
        $error = 'El nombre del negocio es obligatorio.';
    } elseif ($form['currency_symbol'] === '') {
        $error = 'El símbolo de moneda es obligatorio (por ejemplo C$ o US$).';
    } elseif ($form['tax_rate'] !== '' && (!is_numeric($form['tax_rate']) || (float) $form['tax_rate'] < 0 || (float) $form['tax_rate'] > 100)) {
        $error = 'El porcentaje de impuesto debe estar entre 0 y 100.';
    } elseif ($form['facebook_url'] !== '' && !preg_match('#^https?://#i', $form['facebook_url'])) {
        $error = 'El enlace de Facebook debe empezar con https://';
    } else {
        foreach ($textFields as $field => $maxLength) {
            if (mb_strlen($form[$field]) > $maxLength) {
                $error = 'Uno de los campos es demasiado largo.';
                break;
            }
        }
    }

    if ($error === '') {
        try {
            foreach (array_keys(settings_image_keys()) as $key) {
                if (($_POST['remove_' . $key] ?? '') === '1') {
                    $form[$key] = '';
                }

                $path = save_setting_image($_FILES[$key] ?? [], $key);

                if ($path !== null) {
                    $form[$key] = $path;
                }
            }

            save_settings($pdo, $form);

            header('Location: ' . APP_BASE_URL . '/admin/settings/?saved=1');
            exit;
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    }
}

render_sales_layout_start('Configuración', 'settings');

?>

<div class="page-header">
    <div>
        <span class="page-eyebrow">CONFIGURACIÓN</span>
        <h1 class="page-title">Datos del negocio</h1>
        <p class="page-description">
            Lo que guardes aquí sale en el catálogo, el panel, las facturas y los catálogos en PDF.
        </p>
    </div>
</div>

<?php if (isset($_GET['migrated'])): ?>
    <div class="admin-notice admin-notice-success">Base de datos actualizada.</div>
<?php endif; ?>

<?php if ($migrationError !== ''): ?>
    <div class="admin-notice admin-notice-error"><?= e($migrationError) ?></div>
<?php endif; ?>

<?php if (count($pendingMigrations) > 0): ?>
    <section class="dashboard-panel settings-panel settings-update">
        <div class="dashboard-panel-header">
            <div>
                <span class="page-eyebrow">ACTUALIZACIÓN</span>
                <h2>Hay <?= count($pendingMigrations) ?> cambio(s) pendientes en la base de datos</h2>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="migrate">
                <button class="admin-button" type="submit">ACTUALIZAR BASE DE DATOS</button>
            </form>
        </div>
        <p class="form-hint">
            Aparecen después de copiar una versión nueva del sistema. Haz un respaldo antes si tienes datos importantes.
        </p>
    </section>
<?php endif; ?>

<?php if (!$tableReady): ?>

    <section class="empty-state">
        <h2>Falta crear la tabla de configuración</h2>
        <p>Pulsa <strong>ACTUALIZAR BASE DE DATOS</strong> arriba y luego recarga esta página.</p>
    </section>

<?php else: ?>

    <?php if (isset($_GET['saved'])): ?>
        <div class="admin-notice admin-notice-success">Configuración guardada.</div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="admin-notice admin-notice-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <section class="dashboard-panel settings-panel">
            <div class="dashboard-panel-header">
                <div>
                    <span class="page-eyebrow">NEGOCIO</span>
                    <h2>Datos generales</h2>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="business_name">Nombre del negocio</label>
                    <input class="form-input" id="business_name" name="business_name" maxlength="120" required
                        value="<?= e($form['business_name']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="business_tagline">Frase corta</label>
                    <input class="form-input" id="business_tagline" name="business_tagline" maxlength="120"
                        value="<?= e($form['business_tagline']) ?>">
                    <p class="form-hint">Sale en la portada del catálogo en PDF. Ej.: Calzado artesanal nicaragüense.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="business_tax_id">RUC (opcional)</label>
                    <input class="form-input" id="business_tax_id" name="business_tax_id" maxlength="40"
                        value="<?= e($form['business_tax_id']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="business_address">Dirección</label>
                    <input class="form-input" id="business_address" name="business_address" maxlength="255"
                        value="<?= e($form['business_address']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="business_phone">Teléfono (opcional)</label>
                    <input class="form-input" id="business_phone" name="business_phone" maxlength="40"
                        value="<?= e($form['business_phone']) ?>">
                    <p class="form-hint">Si lo dejas vacío, la factura muestra el WhatsApp.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="business_email">Correo (opcional)</label>
                    <input class="form-input" id="business_email" name="business_email" type="email" maxlength="190"
                        value="<?= e($form['business_email']) ?>">
                </div>
            </div>
        </section>

        <section class="dashboard-panel settings-panel">
            <div class="dashboard-panel-header">
                <div>
                    <span class="page-eyebrow">CONTACTO</span>
                    <h2>WhatsApp y redes sociales</h2>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="whatsapp_number">WhatsApp</label>
                    <input class="form-input" id="whatsapp_number" name="whatsapp_number" maxlength="40"
                        placeholder="+505 8888 8888" value="<?= e($form['whatsapp_number']) ?>">
                    <p class="form-hint">Con el código de país. Los pedidos del catálogo llegan a este número.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="instagram_user">Usuario de Instagram</label>
                    <input class="form-input" id="instagram_user" name="instagram_user" maxlength="80"
                        placeholder="@mitienda" value="<?= e($form['instagram_user']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="tiktok_user">Usuario de TikTok</label>
                    <input class="form-input" id="tiktok_user" name="tiktok_user" maxlength="80"
                        placeholder="@mitienda" value="<?= e($form['tiktok_user']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="facebook_url">Enlace de Facebook</label>
                    <input class="form-input" id="facebook_url" name="facebook_url" maxlength="255"
                        placeholder="https://www.facebook.com/mitienda" value="<?= e($form['facebook_url']) ?>">
                </div>
            </div>
            <p class="form-hint">Las redes que dejes vacías no se muestran en el catálogo.</p>
        </section>

        <section class="dashboard-panel settings-panel">
            <div class="dashboard-panel-header">
                <div>
                    <span class="page-eyebrow">IMAGEN</span>
                    <h2>Logo y portada</h2>
                </div>
            </div>

            <div class="form-grid">
                <?php foreach (settings_image_keys() as $key => $label): ?>
                    <div class="form-group">
                        <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                        <?php if ($form[$key] !== ''): ?>
                            <img class="settings-image-preview" src="<?= e(setting_image_url($key)) ?>" alt="">
                        <?php endif; ?>
                        <input class="form-input" id="<?= e($key) ?>" name="<?= e($key) ?>" type="file"
                            accept="image/png,image/jpeg,image/webp,image/svg+xml">
                        <?php if ($form[$key] !== '' && $key !== 'logo_path'): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="remove_<?= e($key) ?>" value="1"> Quitar imagen
                            </label>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="form-hint">PNG, JPG, WEBP o SVG de hasta 5 MB. Si no subes logo del pie, se usa el logo principal.</p>
        </section>

        <section class="dashboard-panel settings-panel">
            <div class="dashboard-panel-header">
                <div>
                    <span class="page-eyebrow">CATÁLOGO</span>
                    <h2>Textos del catálogo</h2>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group form-group-full">
                    <label class="form-label" for="hero_text">Texto de la portada</label>
                    <textarea class="form-textarea" id="hero_text" name="hero_text" maxlength="500"><?= e($form['hero_text']) ?></textarea>
                </div>
                <div class="form-group form-group-full">
                    <label class="form-label" for="about_text">Sobre nosotros (catálogo en PDF)</label>
                    <textarea class="form-textarea" id="about_text" name="about_text" maxlength="3000"><?= e($form['about_text']) ?></textarea>
                    <p class="form-hint">Deja una línea en blanco entre párrafos.</p>
                </div>
            </div>
        </section>

        <section class="dashboard-panel settings-panel">
            <div class="dashboard-panel-header">
                <div>
                    <span class="page-eyebrow">VENTAS</span>
                    <h2>Moneda e impuestos</h2>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="currency_symbol">Símbolo de moneda</label>
                    <input class="form-input" id="currency_symbol" name="currency_symbol" maxlength="6" required
                        value="<?= e($form['currency_symbol']) ?>">
                    <p class="form-hint">Ej.: C$ o US$.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="tax_enabled">Impuesto en la factura</label>
                    <select class="form-select" id="tax_enabled" name="tax_enabled">
                        <option value="0" <?= $form['tax_enabled'] !== '1' ? 'selected' : '' ?>>No mostrar impuesto</option>
                        <option value="1" <?= $form['tax_enabled'] === '1' ? 'selected' : '' ?>>Mostrar impuesto</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="tax_label">Nombre del impuesto</label>
                    <input class="form-input" id="tax_label" name="tax_label" maxlength="20"
                        value="<?= e($form['tax_label']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="tax_rate">Porcentaje (%)</label>
                    <input class="form-input" id="tax_rate" name="tax_rate" type="number" min="0" max="100" step="0.01"
                        value="<?= e($form['tax_rate']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="prices_include_tax">Los precios de venta</label>
                    <select class="form-select" id="prices_include_tax" name="prices_include_tax">
                        <option value="1" <?= $form['prices_include_tax'] === '1' ? 'selected' : '' ?>>Ya incluyen el impuesto</option>
                        <option value="0" <?= $form['prices_include_tax'] !== '1' ? 'selected' : '' ?>>No lo incluyen (se suma al total)</option>
                    </select>
                </div>
            </div>
        </section>

        <?php $license = license_status(); ?>
        <section class="dashboard-panel settings-panel" id="licencia">
            <div class="dashboard-panel-header">
                <div>
                    <span class="page-eyebrow">LICENCIA</span>
                    <h2><?= $license['valid'] ? 'Licencia de ' . e($license['license']['customer'] ?? '') : 'Sin licencia válida' ?></h2>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group form-group-full">
                    <?php if ($license['valid']): ?>
                        <p class="settings-license-info">
                            Módulos:
                            <?php
                            $licensedModules = array_intersect_key(license_modules(), array_flip((array) ($license['license']['modules'] ?? [])));
                            echo e($licensedModules === [] ? 'paquete Básico' : 'Básico + ' . implode(', ', $licensedModules));
                            ?>.
                            <?php if (!empty($license['license']['support_until'])): ?>
                                Soporte hasta el <?= e(date('d/m/Y', strtotime($license['license']['support_until']))) ?>.
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <p class="settings-license-info settings-license-invalid"><?= e($license['message']) ?></p>
                    <?php endif; ?>
                    <label class="form-label" for="license_key">Clave de licencia</label>
                    <textarea class="form-textarea settings-license-key" id="license_key" name="license_key" maxlength="4000"
                        spellcheck="false"><?= e($form['license_key']) ?></textarea>
                    <p class="form-hint">La entrega BMO Studios al comprar el sistema.</p>
                </div>
            </div>
        </section>

        <div class="settings-actions">
            <button class="admin-button" type="submit">GUARDAR CONFIGURACIÓN</button>
        </div>
    </form>

<?php endif; ?>

<?php render_sales_layout_end(); ?>
