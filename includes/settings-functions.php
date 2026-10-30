<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';


/*
|--------------------------------------------------------------------------
| Business Settings
|--------------------------------------------------------------------------
|
| Datos del negocio (nombre, logo, contacto, moneda, impuestos) que se
| editan en Administración > Configuración y se guardan en la tabla
| settings (database/migrations/008_settings.sql). Mientras la tabla no
| exista, el sistema usa los valores de settings_defaults().
|
*/

const SETTINGS_UPLOAD_DIR = 'uploads/branding';

function settings_defaults(): array
{
    return [
        'business_name' => 'Mi Tienda',
        'business_tagline' => 'Calzado',
        'business_tax_id' => '',
        'business_address' => 'Nicaragua',
        'business_phone' => '',
        'business_email' => '',
        'whatsapp_number' => '',
        'instagram_user' => '',
        'facebook_url' => '',
        'tiktok_user' => '',
        'logo_path' => 'assets/logo-placeholder.svg',
        'logo_footer_path' => '',
        'hero_image_path' => '',
        'favicon_path' => '',
        'hero_text' => 'Diseños creados para acompañarte a caminar con seguridad, elegancia y autenticidad.',
        'about_text' => '',
        'currency_symbol' => 'C$',
        'tax_enabled' => '0',
        'tax_label' => 'IVA',
        'tax_rate' => '15',
        'prices_include_tax' => '1',
        'license_key' => '',
    ];
}

/**
 * Settings that hold an uploaded image (see save_setting_image()).
 */
function settings_image_keys(): array
{
    return [
        'logo_path' => 'Logo principal',
        'logo_footer_path' => 'Logo del pie de página (opcional, sobre fondo oscuro)',
        'hero_image_path' => 'Imagen de portada del catálogo',
        'favicon_path' => 'Ícono de la pestaña (favicon), cuadrado',
    ];
}

function settings_table_exists(PDO $pdo): bool
{
    static $exists = null;

    if ($exists === null) {
        try {
            $pdo->query('SELECT 1 FROM settings LIMIT 1');
            $exists = true;
        } catch (PDOException) {
            $exists = false;
        }
    }

    return $exists;
}

/**
 * All settings, stored values over the defaults. Cached per request;
 * pass $reload after saving.
 */
function app_settings(bool $reload = false): array
{
    static $settings = null;

    if ($settings !== null && !$reload) {
        return $settings;
    }

    $settings = settings_defaults();
    $pdo = $GLOBALS['pdo'] ?? null;

    if ($pdo instanceof PDO && settings_table_exists($pdo)) {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();

        foreach ($rows as $row) {
            if (array_key_exists($row['setting_key'], $settings)) {
                $settings[$row['setting_key']] = (string) $row['setting_value'];
            }
        }
    }

    return $settings;
}

function setting(string $key): string
{
    return app_settings()[$key] ?? '';
}

function save_settings(PDO $pdo, array $values): void
{
    $statement = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value)
         VALUES (:setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach ($values as $key => $value) {
        if (!array_key_exists($key, settings_defaults())) {
            continue;
        }

        $statement->execute([
            'setting_key' => $key,
            'setting_value' => (string) $value,
        ]);
    }

    app_settings(true);
}

/**
 * Saves an uploaded image under uploads/branding and returns its
 * relative path, or null when nothing was uploaded. Throws on bad files.
 */
function save_setting_image(array $file, string $key): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir la imagen.');
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('La imagen no puede pesar más de 5 MB.');
    }

    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('La imagen debe ser PNG, JPG, WEBP o SVG.');
    }

    $directory = __DIR__ . '/../' . SETTINGS_UPLOAD_DIR;

    if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
        throw new RuntimeException('No se pudo crear la carpeta ' . SETTINGS_UPLOAD_DIR . '.');
    }

    $name = str_replace('_path', '', $key) . '-' . date('YmdHis') . '.' . $extensions[$mime];

    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $name)) {
        throw new RuntimeException('No se pudo guardar la imagen.');
    }

    return SETTINGS_UPLOAD_DIR . '/' . $name;
}


/*
|--------------------------------------------------------------------------
| Shortcuts used by the pages
|--------------------------------------------------------------------------
*/

function business_name(): string
{
    return setting('business_name');
}

/**
 * URL of an image setting, or of $fallbackKey's image when it is empty.
 */
function setting_image_url(string $key, string $fallbackKey = 'logo_path'): string
{
    $path = setting($key) ?: setting($fallbackKey) ?: settings_defaults()['logo_path'];
    $version = (int) @filemtime(__DIR__ . '/../' . $path);

    return APP_BASE_URL . '/' . ltrim($path, '/') . ($version > 0 ? '?v=' . $version : '');
}

function business_logo_url(): string
{
    return setting_image_url('logo_path');
}

function business_footer_logo_url(): string
{
    return setting_image_url('logo_footer_path');
}

/**
 * Uploaded favicon, or a square with the business initial when there is none.
 */
function business_favicon_url(): string
{
    if (setting('favicon_path') !== '') {
        return setting_image_url('favicon_path');
    }

    $initial = mb_strtoupper(mb_substr(trim(business_name()), 0, 1)) ?: '·';
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">' .
        '<rect width="64" height="64" rx="14" fill="#1d1a18"/>' .
        '<text x="32" y="44" text-anchor="middle" font-family="Georgia, serif" font-size="36" fill="#ffffff">' .
        htmlspecialchars($initial, ENT_XML1) . '</text></svg>';

    return 'data:image/svg+xml,' . rawurlencode($svg);
}

function currency_symbol(): string
{
    return setting('currency_symbol');
}

/**
 * WhatsApp number with digits only (50558458858), for wa.me links.
 */
function whatsapp_digits(): string
{
    return preg_replace('/\D+/', '', setting('whatsapp_number')) ?? '';
}

function whatsapp_link(string $message = ''): string
{
    return 'https://wa.me/' . whatsapp_digits() . ($message !== '' ? '?text=' . rawurlencode($message) : '');
}

function social_handle(string $key): string
{
    return ltrim(trim(setting($key)), '@');
}

function instagram_url(): string
{
    $user = social_handle('instagram_user');

    return $user === '' ? '' : 'https://www.instagram.com/' . rawurlencode($user);
}

function tiktok_url(): string
{
    $user = social_handle('tiktok_user');

    return $user === '' ? '' : 'https://www.tiktok.com/@' . rawurlencode($user);
}

function tax_enabled(): bool
{
    return setting('tax_enabled') === '1';
}

function tax_rate(): float
{
    return max(0.0, (float) setting('tax_rate')) / 100;
}

function prices_include_tax(): bool
{
    return setting('prices_include_tax') === '1';
}
