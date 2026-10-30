<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Database Migrations
|--------------------------------------------------------------------------
|
| database/alterego.sql crea las tablas base y database/migrations/*.sql
| agrega lo demás, en orden. La tabla schema_migrations guarda qué
| archivos ya se aplicaron, así el instalador y el botón "Actualizar base
| de datos" de Configuración aplican solo los que faltan.
|
| Una base que ya tenía migraciones importadas a mano (phpMyAdmin) no
| tiene schema_migrations: si un archivo choca con una tabla, columna o
| índice que ya existe, se da por aplicado.
|
*/

const MIGRATION_BASE_FILE = 'base';

/**
 * Splits a .sql file into statements. Drops comments and the
 * CREATE DATABASE / USE lines, so the files run on whatever database
 * the connection uses.
 */
function sql_statements(string $sql): array
{
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql) ?? '';
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';

    $statements = [];

    foreach (preg_split('/;\s*(?:\R|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);

        if ($statement === '' || preg_match('/^(USE\s|CREATE\s+DATABASE\s)/i', $statement)) {
            continue;
        }

        $statements[] = $statement;
    }

    return $statements;
}

/**
 * Every migration in order: the base schema first, then the numbered files.
 * Keys are the names saved in schema_migrations.
 */
function migration_files(): array
{
    $root = __DIR__ . '/../database';
    $files = [MIGRATION_BASE_FILE => $root . '/alterego.sql'];

    $numbered = glob($root . '/migrations/*.sql') ?: [];
    sort($numbered, SORT_STRING);

    foreach ($numbered as $file) {
        $files[basename($file, '.sql')] = $file;
    }

    return $files;
}

function ensure_migrations_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(120) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) DEFAULT CHARSET = utf8mb4'
    );
}

function applied_migrations(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException) {
        return [];
    }
}

function pending_migrations(PDO $pdo): array
{
    $applied = array_flip(applied_migrations($pdo));

    return array_diff_key(migration_files(), $applied);
}

/**
 * MySQL errors that mean "this was already done": table, column or index
 * already exists, or the row is already there.
 */
function migration_error_already_applied(PDOException $exception): bool
{
    $code = (int) ($exception->errorInfo[1] ?? 0);

    return in_array($code, [1050, 1060, 1061, 1062, 1826], true);
}

/**
 * Applies the pending migrations and returns their names.
 * Throws RuntimeException naming the file that failed.
 */
function run_pending_migrations(PDO $pdo): array
{
    ensure_migrations_table($pdo);

    $applied = [];
    $record = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');

    foreach (pending_migrations($pdo) as $name => $file) {
        $sql = file_get_contents($file);

        if ($sql === false) {
            throw new RuntimeException('No se pudo leer ' . basename($file) . '.');
        }

        foreach (sql_statements($sql) as $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $exception) {
                if (!migration_error_already_applied($exception)) {
                    throw new RuntimeException(
                        'Falló ' . basename($file) . ': ' . $exception->getMessage(),
                        0,
                        $exception
                    );
                }
            }
        }

        $record->execute(['migration' => $name]);
        $applied[] = $name;
    }

    return $applied;
}
