<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tallas
|--------------------------------------------------------------------------
|
| The store sells sizes 5 to 10 (US). Until 2026-11-03 the system saved
| them as 35 to 40, which confused customers. store_sizes() is the list
| every form uses, and convert_legacy_sizes() turns the saved 35-40 into
| 5-10 once (35 → 5 … 40 → 10, also 34 and 41-44 and half sizes) in
| product_variants (with the SKU suffix), sale_items and workshop lines.
| It runs on the first admin page after the update, after a backup.
|
*/

function store_sizes(): array
{
    return ['5', '6', '7', '8', '9', '10'];
}

function legacy_sizes_pending(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query(
            "SELECT 1 FROM product_variants WHERE size REGEXP '^(3[4-9]|4[0-4])(\\\\.5)?$' LIMIT 1"
        )->fetchColumn();
    } catch (PDOException) {
        return false;
    }
}

function convert_legacy_sizes(PDO $pdo): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    if (!legacy_sizes_pending($pdo)) {
        return;
    }

    // Safety copy before touching the data (Respaldos > "...-tallas").
    try {
        require_once __DIR__ . '/backup-functions.php';
        create_backup($pdo, 'tallas');
    } catch (Throwable) {
        // A failed backup must not block the admin; the daily backups remain.
    }

    $pattern = "'^(3[4-9]|4[0-4])(\\\\.5)?$'";
    $newSize = "CONCAT(FLOOR(CAST(size AS DECIMAL(4,1))) - 30, IF(size LIKE '%.5', '.5', ''))";

    $statements = [
        // SKU first, while size still holds the old value.
        "UPDATE IGNORE product_variants
         SET sku = IF(sku IS NOT NULL AND sku LIKE CONCAT('%-', size), CONCAT(LEFT(sku, CHAR_LENGTH(sku) - CHAR_LENGTH(size)), $newSize), sku),
             size = $newSize
         WHERE size REGEXP $pattern",
        "UPDATE sale_items SET size = $newSize WHERE size REGEXP $pattern",
        "UPDATE workshop_order_lines SET size = $newSize WHERE size REGEXP $pattern",
    ];

    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException) {
            // Table missing on older installs: nothing to convert there.
        }
    }
}
