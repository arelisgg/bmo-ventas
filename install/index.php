<?php

declare(strict_types=1);

define('APP_INSTALLING', true);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/settings-functions.php';
require_once __DIR__ . '/../includes/migration-functions.php';


/*
|--------------------------------------------------------------------------
| Installer
|--------------------------------------------------------------------------
|
| Crea la base de datos y sus tablas, el usuario dueño y config/local.php
| con los datos de conexión. Cuando config/local.php existe, el sistema ya
| está instalado y esta página no hace nada (para reinstalar, borra ese
| archivo). Las actualizaciones posteriores se aplican desde
| Administración > Configuración.
|
*/

$localConfigFile = __DIR__ . '/../config/local.php';
$installed = is_file($localConfigFile);
$error = '';
$done = false;
$localConfigContent = '';

$form = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'bmo_ventas',
    'db_user' => 'root',
    'db_pass' => '',
    'business_name' => '',
    'currency_symbol' => 'C$',
    'owner_name' => '',
    'owner_email' => '',
];

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $field) {
        $value = $_POST[$field] ?? '';
        $form[$field] = is_string($value) ? trim($value) : '';
    }

    $form['owner_email'] = strtolower($form['owner_email']);
    $ownerPassword = (string) ($_POST['owner_password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $form['db_name'])) {
        $error = 'El nombre de la base de datos solo puede tener letras, números y guion bajo.';
    } elseif (!ctype_digit($form['db_port'])) {
        $error = 'El puerto debe ser un número.';
    } elseif ($form['business_name'] === '') {
        $error = 'Escribe el nombre del negocio.';
    } elseif ($form['owner_name'] === '' || !filter_var($form['owner_email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Escribe el nombre y un correo válido para el usuario dueño.';
    } elseif (mb_strlen($ownerPassword) < 8) {
        $error = 'La contraseña del dueño debe tener al menos 8 caracteres.';
    } else {
        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $server = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $form['db_host'], $form['db_port']),
                $form['db_user'],
                $form['db_pass'],
                $options
            );
            $server->exec(
                'CREATE DATABASE IF NOT EXISTS `' . $form['db_name'] . '`
                 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );

            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $form['db_host'], $form['db_port'], $form['db_name']),
                $form['db_user'],
                $form['db_pass'],
                $options
            );
            $GLOBALS['pdo'] = $pdo;

            run_pending_migrations($pdo);

            save_settings($pdo, [
                'business_name' => $form['business_name'],
                'currency_symbol' => $form['currency_symbol'] !== '' ? $form['currency_symbol'] : 'C$',
            ]);

            $pdo->prepare(
                "INSERT INTO admins (name, email, password, role, is_active)
                 VALUES (:name, :email, :password, 'owner', 1)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name), password = VALUES(password), role = 'owner', is_active = 1"
            )->execute([
                'name' => $form['owner_name'],
                'email' => $form['owner_email'],
                'password' => password_hash($ownerPassword, PASSWORD_DEFAULT),
            ]);

            $localConfigContent =
                "<?php\n\n" .
                "declare(strict_types=1);\n\n" .
                "// Creado por el instalador el " . date('Y-m-d H:i') . ". Datos de conexión a la base de datos.\n\n" .
                '$dbHost = ' . var_export($form['db_host'], true) . ";\n" .
                '$dbPort = ' . var_export($form['db_port'], true) . ";\n" .
                '$dbName = ' . var_export($form['db_name'], true) . ";\n" .
                '$dbUser = ' . var_export($form['db_user'], true) . ";\n" .
                '$dbPass = ' . var_export($form['db_pass'], true) . ";\n";

            $done = @file_put_contents($localConfigFile, $localConfigContent) !== false;

            if (!$done) {
                $error = 'Todo quedó instalado, pero no se pudo crear config/local.php. Créalo a mano con el contenido de abajo.';
            }
        } catch (PDOException $exception) {
            $error = 'No se pudo conectar o crear la base de datos: ' . $exception->getMessage();
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    }
}

$base = APP_BASE_URL;

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación del sistema</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?= e($base) ?>/admin/admin.css?v=<?= (int) @filemtime(__DIR__ . '/../admin/admin.css') ?>">
</head>

<body>
    <main class="admin-content install-page">

        <div class="page-header">
            <div>
                <span class="page-eyebrow">INSTALACIÓN</span>
                <h1 class="page-title">Instalar el sistema</h1>
                <p class="page-description">
                    Crea la base de datos, las tablas y el usuario dueño. Se hace una sola vez por tienda.
                </p>
            </div>
        </div>

        <?php if ($installed && !$done): ?>

            <section class="empty-state">
                <h2>El sistema ya está instalado</h2>
                <p>
                    Para reinstalarlo, borra el archivo <strong>config/local.php</strong>.
                    Las actualizaciones de la base de datos se aplican desde Administración &gt; Configuración.
                </p>
                <p><a class="admin-button" href="<?= e($base) ?>/admin/">IR AL PANEL</a></p>
            </section>

        <?php elseif ($done): ?>

            <section class="empty-state">
                <h2>Instalación completa</h2>
                <p>
                    Entra con <strong><?= e($form['owner_email']) ?></strong> y la contraseña que elegiste.
                    Luego completa logo, WhatsApp y redes en Configuración.
                </p>
                <p><a class="admin-button" href="<?= e($base) ?>/admin/login.php">ENTRAR AL PANEL</a></p>
            </section>

        <?php else: ?>

            <?php if ($error !== ''): ?>
                <div class="admin-notice admin-notice-error"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($localConfigContent !== ''): ?>
                <pre class="install-config"><?= e($localConfigContent) ?></pre>
            <?php endif; ?>

            <form method="post">
                <section class="dashboard-panel settings-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <span class="page-eyebrow">PASO 1</span>
                            <h2>Base de datos</h2>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="db_host">Servidor</label>
                            <input class="form-input" id="db_host" name="db_host" required value="<?= e($form['db_host']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="db_port">Puerto</label>
                            <input class="form-input" id="db_port" name="db_port" required value="<?= e($form['db_port']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="db_name">Nombre de la base de datos</label>
                            <input class="form-input" id="db_name" name="db_name" required value="<?= e($form['db_name']) ?>">
                            <p class="form-hint">Se crea si no existe. Si ya existe, solo se agregan las tablas que falten.</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="db_user">Usuario</label>
                            <input class="form-input" id="db_user" name="db_user" required value="<?= e($form['db_user']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="db_pass">Contraseña</label>
                            <input class="form-input" id="db_pass" name="db_pass" type="password" value="<?= e($form['db_pass']) ?>">
                            <p class="form-hint">En XAMPP normalmente el usuario es root y la contraseña va vacía.</p>
                        </div>
                    </div>
                </section>

                <section class="dashboard-panel settings-panel">
                    <div class="dashboard-panel-header">
                        <div>
                            <span class="page-eyebrow">PASO 2</span>
                            <h2>Negocio y usuario dueño</h2>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="business_name">Nombre del negocio</label>
                            <input class="form-input" id="business_name" name="business_name" maxlength="120" required
                                value="<?= e($form['business_name']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="currency_symbol">Moneda</label>
                            <select class="form-select" id="currency_symbol" name="currency_symbol">
                                <?php foreach (['C$' => 'Córdobas (C$)', 'US$' => 'Dólares (US$)'] as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $form['currency_symbol'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="owner_name">Nombre del dueño</label>
                            <input class="form-input" id="owner_name" name="owner_name" maxlength="120" required
                                value="<?= e($form['owner_name']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="owner_email">Correo del dueño (para entrar)</label>
                            <input class="form-input" id="owner_email" name="owner_email" type="email" maxlength="190" required
                                value="<?= e($form['owner_email']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="owner_password">Contraseña del dueño</label>
                            <input class="form-input" id="owner_password" name="owner_password" type="password" minlength="8"
                                autocomplete="new-password" required>
                            <p class="form-hint">Mínimo 8 caracteres.</p>
                        </div>
                    </div>
                </section>

                <div class="settings-actions">
                    <button class="admin-button" type="submit">INSTALAR</button>
                </div>
            </form>

        <?php endif; ?>

    </main>
</body>

</html>
