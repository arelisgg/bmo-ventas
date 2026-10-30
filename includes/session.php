<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Secure Session Configuration
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

$isHttps =
    isset($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== '' &&
    $_SERVER['HTTPS'] !== 'off';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_name('APP_ADMIN_SESSION');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();