<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Application Configuration
|--------------------------------------------------------------------------
*/

define('APP_NAME', 'Sistema de ventas');

/*
| URL path of the system ('' at the root of a domain, '/bmo-ventas' in
| XAMPP), worked out from where this folder sits inside the web root, so
| the same files run on any server. If a server reports it wrong, replace
| the detection with the fixed path, e.g. define('APP_BASE_URL', '/tienda');
*/

if (!defined('APP_BASE_URL')) {
    $appRoot = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    $documentRoot = str_replace('\\', '/', (string) realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')));

    define(
        'APP_BASE_URL',
        $documentRoot !== '' && stripos($appRoot, $documentRoot) === 0
            ? rtrim(substr($appRoot, strlen($documentRoot)), '/')
            : '/bmo-ventas'
    );
}

define('ADMIN_SESSION_TIMEOUT', 14400);

/*
| Hora local del negocio. Los hostings suelen estar en hora UTC, y sin esto
| "hoy" en el panel cambia a las 6 de la tarde en Nicaragua.
*/

define('APP_TIMEZONE', 'America/Managua');

date_default_timezone_set(APP_TIMEZONE);