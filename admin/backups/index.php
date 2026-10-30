<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/backup-functions.php';
require_once __DIR__ . '/../sales/_layout.php';

require_admin();


/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| Lista los respaldos, crea uno al momento y permite descargarlos. Solo
| el dueño entra aquí.
|
*/

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido. Recarga la página e intenta de nuevo.';
    } else {
        try {
            $name = create_backup($pdo, 'manual');

            header('Location: ' . APP_BASE_URL . '/admin/backups/?created=' . rawurlencode($name));
            exit;
        } catch (Throwable $exception) {
            $error = 'No se pudo crear el respaldo: ' . $exception->getMessage();
        }
    }
}

$backups = [];

try {
    $backups = list_backups($pdo);
} catch (RuntimeException $exception) {
    $error = $exception->getMessage();
}

render_sales_layout_start('Respaldos', 'backups');

?>

<div class="page-header">
    <div>
        <span class="page-eyebrow">CONFIGURACIÓN</span>
        <h1 class="page-title">Respaldos</h1>
        <p class="page-description">
            Se hace una copia automática al día al abrir el panel y se guardan las últimas <?= BACKUP_KEEP ?>.
            Descarga una de vez en cuando y guárdala fuera de esta computadora.
        </p>
    </div>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <button class="admin-button" type="submit">CREAR RESPALDO AHORA</button>
    </form>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="admin-notice admin-notice-success">Respaldo creado.</div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="admin-notice admin-notice-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if (!backup_zip_available()): ?>
    <div class="admin-notice">
        Los respaldos solo incluyen la base de datos. Para incluir también las fotos, activa la extensión
        <strong>zip</strong> de PHP (en XAMPP: quita el ; de la línea <strong>extension=zip</strong> en php.ini y reinicia Apache).
    </div>
<?php endif; ?>

<section class="dashboard-panel settings-panel">
    <div class="dashboard-panel-header">
        <div>
            <span class="page-eyebrow">COPIAS</span>
            <h2>Respaldos guardados</h2>
        </div>
    </div>

    <?php if ($backups === []): ?>
        <p class="form-hint backups-empty">Todavía no hay respaldos.</p>
    <?php else: ?>
        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Tamaño</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><?= e(date('d/m/Y h:i a', $backup['time'])) ?></td>
                            <td><?= e($backup['kind']) ?></td>
                            <td><?= e(format_file_size($backup['size'])) ?></td>
                            <td>
                                <a class="table-action-link"
                                    href="<?= e(APP_BASE_URL) ?>/admin/backups/download.php?file=<?= e(rawurlencode($backup['name'])) ?>">
                                    Descargar
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="dashboard-panel settings-panel">
    <div class="dashboard-panel-header">
        <div>
            <span class="page-eyebrow">AYUDA</span>
            <h2>Cómo restaurar un respaldo</h2>
        </div>
    </div>
    <ol class="backups-help">
        <li>Descarga el respaldo y descomprímelo.</li>
        <li>En phpMyAdmin, entra a la base de datos del sistema e importa <strong>base-de-datos.sql</strong>. Reemplaza todos los datos por los del respaldo.</li>
        <li>Si el respaldo trae la carpeta <strong>uploads</strong>, cópiala encima de la carpeta uploads del sistema.</li>
    </ol>
</section>

<?php render_sales_layout_end(); ?>
