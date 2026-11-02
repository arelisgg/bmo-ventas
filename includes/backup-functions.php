<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| Copia de la base de datos (y de las fotos en uploads/ cuando PHP tiene
| la extensión zip) en la carpeta backups/, que no se puede abrir desde
| el navegador. Se crea una sola vez al día al abrir el panel, y el dueño
| puede crear y descargar copias en Administración > Respaldos. Se
| guardan las últimas BACKUP_KEEP.
|
*/

const BACKUP_KEEP = 14;
const BACKUP_DAILY_SECONDS = 86400;

function backup_directory(): string
{
    $directory = __DIR__ . '/../backups';

    if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
        throw new RuntimeException('No se pudo crear la carpeta backups/.');
    }

    if (!is_file($directory . '/.htaccess')) {
        file_put_contents($directory . '/.htaccess', "Require all denied\n");
        file_put_contents($directory . '/index.html', '');
    }

    return $directory;
}

function backup_database_name(PDO $pdo): string
{
    return (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
}

function backup_zip_available(): bool
{
    return class_exists('ZipArchive');
}

/**
 * Writes a full SQL dump (tables + rows) of the current database to $file.
 */
function write_database_dump(PDO $pdo, string $file): void
{
    $handle = fopen($file, 'wb');

    if ($handle === false) {
        throw new RuntimeException('No se pudo escribir el respaldo.');
    }

    fwrite($handle, '-- Respaldo de ' . backup_database_name($pdo) . ' · ' . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "-- Para restaurarlo, impórtalo en phpMyAdmin sobre la base de datos del sistema.\n\n");
    fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

    foreach ($tables as [$table]) {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $create = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);

        fwrite($handle, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n" . $create[1] . ";\n\n");

        $rows = $pdo->query('SELECT * FROM ' . $quotedTable, PDO::FETCH_NUM);
        $batch = [];

        foreach ($rows as $row) {
            $batch[] = '(' . implode(', ', array_map(
                static fn (mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
                $row
            )) . ')';

            if (count($batch) === 200) {
                fwrite($handle, 'INSERT INTO ' . $quotedTable . " VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }

        if ($batch !== []) {
            fwrite($handle, 'INSERT INTO ' . $quotedTable . " VALUES\n" . implode(",\n", $batch) . ";\n");
        }

        fwrite($handle, "\n");
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($handle);
}

/**
 * Creates a backup and returns its file name. $kind is "auto" or "manual".
 */
function create_backup(PDO $pdo, string $kind = 'manual'): string
{
    @set_time_limit(300);

    $directory = backup_directory();
    $name = backup_database_name($pdo) . '-' . date('Y-m-d-His') . '-' . $kind;
    $sqlFile = $directory . '/' . $name . '.sql';

    write_database_dump($pdo, $sqlFile);

    if (backup_zip_available()) {
        $zip = new ZipArchive();

        if ($zip->open($directory . '/' . $name . '.zip', ZipArchive::CREATE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo zip del respaldo.');
        }

        $zip->addFile($sqlFile, 'base-de-datos.sql');

        $uploads = realpath(__DIR__ . '/../uploads');

        if ($uploads !== false) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->isFile()) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($uploads) + 1));
                    $zip->addFile($file->getPathname(), 'uploads/' . $relative);
                }
            }
        }

        $zip->close();
        unlink($sqlFile);

        $name .= '.zip';
    } else {
        $compressed = gzencode((string) file_get_contents($sqlFile), 6);

        if ($compressed === false) {
            throw new RuntimeException('No se pudo comprimir el respaldo.');
        }

        file_put_contents($sqlFile . '.gz', $compressed);
        unlink($sqlFile);

        $name .= '.sql.gz';
    }

    prune_backups($pdo);

    return $name;
}

/**
 * Backups of the current database, newest first.
 */
function list_backups(PDO $pdo): array
{
    $prefix = backup_database_name($pdo) . '-';
    $backups = [];

    foreach (glob(backup_directory() . '/*') ?: [] as $file) {
        $name = basename($file);

        if (str_starts_with($name, $prefix) && preg_match('/\.(zip|sql\.gz)$/', $name)) {
            $backups[] = [
                'name' => $name,
                'size' => (int) filesize($file),
                'time' => (int) filemtime($file),
                'kind' => str_contains($name, '-auto.') ? 'Automático' : 'Manual',
            ];
        }
    }

    usort($backups, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);

    return $backups;
}

function prune_backups(PDO $pdo): void
{
    foreach (array_slice(list_backups($pdo), BACKUP_KEEP) as $backup) {
        @unlink(backup_directory() . '/' . $backup['name']);
    }
}

/**
 * Path of a backup by name, or null when the name is not one of ours.
 */
function backup_path(PDO $pdo, string $name): ?string
{
    foreach (list_backups($pdo) as $backup) {
        if ($backup['name'] === $name) {
            return backup_directory() . '/' . $name;
        }
    }

    return null;
}

/**
 * Called from the dashboard: makes the daily backup when the last one is
 * older than a day. Never breaks the page.
 */
function run_daily_backup(PDO $pdo): void
{
    if (defined('APP_DEMO_MODE') && APP_DEMO_MODE) {
        return;
    }

    try {
        $latest = list_backups($pdo)[0]['time'] ?? 0;

        if (time() - $latest >= BACKUP_DAILY_SECONDS) {
            create_backup($pdo, 'auto');
        }
    } catch (Throwable $exception) {
        error_log('Respaldo automático: ' . $exception->getMessage());
    }
}

function format_file_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }

    return number_format(max(1, $bytes / 1024), 0) . ' KB';
}
