<?php

declare(strict_types=1);

require_once __DIR__ . '/settings-functions.php';


/*
|--------------------------------------------------------------------------
| License
|--------------------------------------------------------------------------
|
| Cada tienda recibe una clave de licencia firmada por BMO Studios (con la
| clave privada que solo tiene BMO Studios, ver la herramienta
| generar-licencia.php que no se entrega al cliente). La clave dice el
| cliente, los dominios donde funciona, los módulos comprados y hasta
| cuándo tiene soporte. Se pega en Administración > Configuración.
|
| Sin licencia válida el sistema sigue vendiendo, pero el panel muestra un
| aviso y solo quedan los módulos del paquete Básico. localhost siempre
| se acepta, para pruebas y demos.
|
*/

const LICENSE_PUBLIC_KEY = <<<'KEY'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAxlxCi9W9vsENzTfz8/ZT
LsOSbciTno1nzJgBgtiFS727Pw+KgK+DwA4w+70HGuoIJnbJ72LclPUgxic8XN5g
ath347tfAx/O5VASVVCsq3tePXcEjUlFkYeGAPFiaxArjnCGJ3W86hNYWaLEXqqT
BBt0wqtFBdZBbRFk36oSVEpW3WXpkcWmcbggMDnj2pgGKZsfRbKL0djBxiqjtEiL
iGUlhA6insGY3N7WS/j3XMzImQ2t3xDgUvoSKlMLBybua/MBoQtFAsROJKnaqwwa
WRffZ/MgAOEiiEYseToZveRaI9lxxdY37mxZTnvox5KfJgLZgAGSSoav8YeivndY
BQIDAQAB
-----END PUBLIC KEY-----
KEY;

function license_modules(): array
{
    return [
        'wholesale' => 'Mayorista',
        'workshop' => 'Pedidos al taller',
        'finance' => 'Finanzas',
    ];
}

function license_current_host(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    return preg_replace('/:\d+$/', '', $host) ?? $host;
}

/**
 * Reads a license key. Returns ['valid' => bool, 'message' => string,
 * 'license' => payload or null].
 */
function check_license_key(string $key, ?string $host = null): array
{
    $result = ['valid' => false, 'message' => '', 'license' => null];
    $parts = explode('.', trim($key));

    if (trim($key) === '') {
        $result['message'] = 'Este sistema no tiene licencia.';

        return $result;
    }

    $payload = count($parts) === 2 ? base64_decode(strtr($parts[0], '-_', '+/'), true) : false;
    $signature = count($parts) === 2 ? base64_decode(strtr($parts[1], '-_', '+/'), true) : false;

    if (
        $payload === false ||
        $signature === false ||
        !function_exists('openssl_verify') ||
        openssl_verify($payload, $signature, LICENSE_PUBLIC_KEY, OPENSSL_ALGO_SHA256) !== 1
    ) {
        $result['message'] = 'La clave de licencia no es válida.';

        return $result;
    }

    $license = json_decode($payload, true);

    if (!is_array($license)) {
        $result['message'] = 'La clave de licencia no es válida.';

        return $result;
    }

    $result['license'] = $license;
    $host ??= license_current_host();
    $domains = array_map('strtolower', (array) ($license['domains'] ?? []));
    $hostAllowed = in_array($host, ['localhost', '127.0.0.1'], true) || in_array('*', $domains, true);

    foreach ($domains as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            $hostAllowed = true;
        }
    }

    if (!$hostAllowed) {
        $result['message'] = 'La licencia es para ' . implode(', ', $domains) . ', no para ' . $host . '.';

        return $result;
    }

    if (!empty($license['expires']) && $license['expires'] < date('Y-m-d')) {
        $result['message'] = 'La licencia venció el ' . $license['expires'] . '.';

        return $result;
    }

    $result['valid'] = true;

    return $result;
}

function license_status(): array
{
    static $status = null;

    // The demo shows every module without a license.
    if ($status === null && defined('APP_DEMO_MODE') && APP_DEMO_MODE) {
        $status = [
            'valid' => true,
            'message' => 'Demo con todos los módulos.',
            'license' => ['customer' => 'Demo', 'modules' => array_keys(license_modules())],
        ];
    }

    return $status ??= check_license_key(setting('license_key'));
}

function license_has_module(string $module): bool
{
    $status = license_status();

    return $status['valid'] && in_array($module, (array) ($status['license']['modules'] ?? []), true);
}

/**
 * Admin sections that belong to a paid module.
 */
function license_section_allowed(string $section): bool
{
    return !isset(license_modules()[$section]) || license_has_module($section);
}
