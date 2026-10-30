<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/backup-functions.php';

require_admin();

$path = backup_path($pdo, (string) ($_GET['file'] ?? ''));

if ($path === null) {
    http_response_code(404);
    exit('Respaldo no encontrado.');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');

readfile($path);
