<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/license-functions.php';


/*
|--------------------------------------------------------------------------
| Check Authentication
|--------------------------------------------------------------------------
*/

function admin_is_logged_in(): bool
{
    // A demo session only counts on the demo database, and a real one only on the real database.
    $demoMode = defined('APP_DEMO_MODE') && APP_DEMO_MODE;

    return isset(
        $_SESSION['admin_id'],
        $_SESSION['admin_email']
    ) && (bool) ($_SESSION['admin_demo'] ?? false) === $demoMode;
}


/*
|--------------------------------------------------------------------------
| Session Timeout
|--------------------------------------------------------------------------
*/

function check_admin_session_timeout(): void
{
    if (!admin_is_logged_in()) {
        return;
    }

    $lastActivity =
        $_SESSION['admin_last_activity'] ?? 0;

    if (
        $lastActivity > 0 &&
        (time() - $lastActivity) > ADMIN_SESSION_TIMEOUT
    ) {
        $_SESSION = [];

        session_regenerate_id(false);

        header(
            'Location: ' .
            APP_BASE_URL .
            '/admin/login.php?expired=1'
        );

        exit;
    }

    $_SESSION['admin_last_activity'] = time();
}


/*
|--------------------------------------------------------------------------
| Protect Admin Pages
|--------------------------------------------------------------------------
*/

function require_admin(): void
{
    $admin = admin_is_logged_in() ? current_admin() : null;

    if ($admin === null || (int) $admin['is_active'] !== 1) {
        $_SESSION = [];

        header(
            'Location: ' .
            APP_BASE_URL .
            '/admin/login.php'
        );

        exit;
    }

    check_admin_session_timeout();

    if (!admin_can(current_admin_section())) {
        deny_admin_section();
    }
}


/*
|--------------------------------------------------------------------------
| Roles
|--------------------------------------------------------------------------
|
| Cada usuario tiene un rol (admins.role, migración 009_admin_roles.sql).
| El dueño entra a todo; vendedor y bodega solo a sus secciones, y no ven
| costos, finanzas, configuración ni usuarios. La sección de cada página
| sale de su carpeta (admin/<sección>/), así require_admin() la protege.
| Sin la migración, todos los usuarios son dueños.
|
*/

function admin_roles(): array
{
    return [
        'owner' => 'Dueño',
        'seller' => 'Vendedor',
        'warehouse' => 'Bodega',
    ];
}

function admin_role_sections(): array
{
    return [
        'owner' => ['*'],
        'seller' => ['dashboard', 'products', 'inventory', 'sales', 'workshop'],
        'warehouse' => ['dashboard', 'products', 'categories', 'inventory', 'workshop'],
    ];
}

function admin_roles_column_exists(PDO $pdo): bool
{
    static $exists = null;

    if ($exists === null) {
        $exists = $pdo->query("SHOW COLUMNS FROM admins LIKE 'role'")->fetch() !== false;
    }

    return $exists;
}

/**
 * The logged in user as stored now (so a role change or a deactivated
 * user applies right away), or null.
 */
function current_admin(): ?array
{
    static $admin = false;

    if ($admin !== false) {
        return $admin;
    }

    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO || !isset($_SESSION['admin_id'])) {
        return $admin = null;
    }

    $statement = $pdo->prepare(
        'SELECT id, name, email, is_active, ' .
        (admin_roles_column_exists($pdo) ? 'role' : "'owner' AS role") .
        ' FROM admins WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => (int) $_SESSION['admin_id']]);

    return $admin = $statement->fetch() ?: null;
}

function admin_role(): string
{
    $role = (string) (current_admin()['role'] ?? 'owner');

    return isset(admin_roles()[$role]) ? $role : 'seller';
}

function admin_role_label(?string $role = null): string
{
    return admin_roles()[$role ?? admin_role()] ?? '';
}

function admin_can(string $section): bool
{
    $sections = admin_role_sections()[admin_role()] ?? [];

    return license_section_allowed($section) &&
        (in_array('*', $sections, true) || in_array($section, $sections, true));
}

function admin_is_owner(): bool
{
    return admin_role() === 'owner';
}

/**
 * Section of the current admin page: admin/index.php is the dashboard,
 * admin/sales/view.php is "sales".
 */
function current_admin_section(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

    if (preg_match('#/admin/([^/]+)/[^/]+\.php$#', $script, $match)) {
        return $match[1];
    }

    return 'dashboard';
}

function deny_admin_section(): never
{
    http_response_code(403);
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sin permiso</title>
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/admin/admin.css">
</head>
<body>
    <main class="admin-content">
        <section class="empty-state">
            <h2>No tienes permiso para entrar aquí</h2>
            <p>Tu usuario es <strong><?= e(admin_role_label()) ?></strong>. Pídele al dueño del negocio que te dé acceso.</p>
            <p><a class="admin-button" href="<?= e(APP_BASE_URL) ?>/admin/">Volver al panel</a></p>
        </section>
    </main>
</body>
</html>
<?php
    exit;
}


/*
|--------------------------------------------------------------------------
| Redirect Logged In Admin
|--------------------------------------------------------------------------
*/

function redirect_if_admin_logged_in(): void
{
    if (!admin_is_logged_in()) {
        return;
    }

    header(
        'Location: ' .
        APP_BASE_URL .
        '/admin/'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Login Attempts
|--------------------------------------------------------------------------
|
| Después de LOGIN_MAX_ATTEMPTS intentos fallidos con el mismo correo o
| desde la misma conexión, el inicio de sesión se bloquea por
| LOGIN_LOCK_MINUTES minutos (tabla login_attempts, migración 010).
|
*/

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 15;

function login_client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function login_is_locked(PDO $pdo, string $email): bool
{
    try {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE (email = :email OR ip_address = :ip)
               AND attempted_at > NOW() - INTERVAL ' . LOGIN_LOCK_MINUTES . ' MINUTE'
        );
        $statement->execute(['email' => $email, 'ip' => login_client_ip()]);

        return (int) $statement->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
    } catch (PDOException) {
        return false;
    }
}

function record_login_failure(PDO $pdo, string $email): void
{
    try {
        $pdo->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (:email, :ip)')
            ->execute(['email' => mb_substr($email, 0, 190), 'ip' => login_client_ip()]);
        $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
    } catch (PDOException) {
    }
}

function clear_login_failures(PDO $pdo, string $email): void
{
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE email = :email OR ip_address = :ip')
            ->execute(['email' => $email, 'ip' => login_client_ip()]);
    } catch (PDOException) {
    }
}
