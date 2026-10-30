<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Database Configuration
|--------------------------------------------------------------------------
|
| El instalador (install/) guarda los datos de conexión en config/local.php.
| Sin ese archivo el sistema todavía no está instalado y manda al instalador.
|
*/

$dbHost = '127.0.0.1';
$dbPort = '3306';
$dbName = 'bmo_ventas';
$dbUser = 'root';
$dbPass = '';

if (is_file(__DIR__ . '/local.php')) {
    require __DIR__ . '/local.php';
} elseif (!defined('APP_INSTALLING')) {
    require_once __DIR__ . '/app.php';

    header('Location: ' . APP_BASE_URL . '/install/');
    exit;
}


/*
|--------------------------------------------------------------------------
| Demo Mode
|--------------------------------------------------------------------------
|
| Off unless config/local.php sets $demoDbName, so client systems never
| show it. The demo user (demo@demo.com / demo1234) then works on that
| database (import database/demo/alterego_demo.sql), so the sample data
| never mixes with the real sales; on a demo-only site use
| $demoDbName = $dbName;. Logging in as the demo user sets a
| cookie that keeps this browser on the demo database until logout.
|
*/

if (!defined('APP_DEMO_MODE')) {
    define('DEMO_EMAIL', 'demo@demo.com');
    define('DEMO_ENABLED', isset($demoDbName));
    define('DEMO_DB_NAME', $demoDbName ?? '');
    define('DEMO_COOKIE', 'APP_DEMO');

    $isLoginPost =
        ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
        basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'login.php';

    define(
        'APP_DEMO_MODE',
        DEMO_ENABLED && (
            $isLoginPost
                ? strtolower(trim((string) ($_POST['email'] ?? ''))) === DEMO_EMAIL
                : ($_COOKIE[DEMO_COOKIE] ?? '') === '1'
        )
    );
}

if (!function_exists('remember_demo_mode')) {
    function remember_demo_mode(bool $demo): void
    {
        setcookie(DEMO_COOKIE, $demo ? '1' : '', [
            'expires' => $demo ? 0 : time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (APP_DEMO_MODE) {
    $dbName = DEMO_DB_NAME;
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $dbHost,
    $dbPort,
    $dbName
);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO(
        $dsn,
        $dbUser,
        $dbPass,
        $options
    );
} catch (PDOException $exception) {

    http_response_code(500);

    if (APP_DEMO_MODE) {
        remember_demo_mode(false);

        exit(
            'La base de datos de demo no está instalada. ' .
            'Importa database/demo/alterego_demo.sql en phpMyAdmin y vuelve a intentarlo.'
        );
    }

    exit(
        'Database connection failed: ' .
        $exception->getMessage()
    );
}

// MySQL uses the same local time as PHP for NOW() and CURDATE().
require_once __DIR__ . '/app.php';

$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");

require_once __DIR__ . '/../includes/settings-functions.php';