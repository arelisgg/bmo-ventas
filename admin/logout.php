<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';


/*
|--------------------------------------------------------------------------
| POST Only
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header(
        'Location: ' .
        APP_BASE_URL .
        '/admin/'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Verify CSRF
|--------------------------------------------------------------------------
*/

$csrfToken =
    $_POST['csrf_token'] ?? null;

if (!verify_csrf_token($csrfToken)) {

    http_response_code(403);

    exit('Invalid security token.');
}


/*
|--------------------------------------------------------------------------
| Clear Authentication
|--------------------------------------------------------------------------
*/

$_SESSION = [];


/*
|--------------------------------------------------------------------------
| Remove Session Cookie
|--------------------------------------------------------------------------
*/

if (ini_get('session.use_cookies')) {

    $cookieParams =
        session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,

            'path' => $cookieParams['path'],

            'domain' => $cookieParams['domain'],

            'secure' => $cookieParams['secure'],

            'httponly' => $cookieParams['httponly'],

            'samesite' => 'Lax',
        ]
    );
}


/*
|--------------------------------------------------------------------------
| Destroy Session
|--------------------------------------------------------------------------
*/

session_destroy();


/*
|--------------------------------------------------------------------------
| Leave Demo Mode
|--------------------------------------------------------------------------
*/

setcookie(defined('DEMO_COOKIE') ? DEMO_COOKIE : 'APP_DEMO', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);


/*
|--------------------------------------------------------------------------
| Redirect
|--------------------------------------------------------------------------
*/

header(
    'Location: ' .
    APP_BASE_URL .
    '/admin/login.php'
);

exit;