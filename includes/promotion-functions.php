<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Promotions
|--------------------------------------------------------------------------
|
| Promotions managed in admin/promotions/ and offered in the sale form.
| Tables come from database/migrations/012_promotions.sql; until it is
| imported every helper here does nothing, so the site keeps working.
|
| Rules (the same ones the sale form uses in JavaScript):
| - The sale form only applies a promotion after the user accepts it.
| - One promotion per sale, and never together with wholesale prices:
|   the form offers whichever saves more and the user confirms.
| - 'bundle': every N units of the chosen categories cost a fixed
|   price. The most expensive units go into the bundles.
| - 'percent': % off the units of the chosen categories.
| - first_purchase_only: the customer (by phone, or by name when there
|   is no phone) has no earlier invoice that was not cancelled.
|
*/

function promotion_tables_exist(PDO $pdo): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $pdo->query('SELECT 1 FROM promotions LIMIT 1');
        $pdo->query('SELECT 1 FROM promotion_categories LIMIT 1');
        $pdo->query('SELECT promotion_id, promotion_name, promotion_discount FROM sales LIMIT 1');

        $exists = true;
    } catch (PDOException) {
        $exists = false;
    }

    return $exists;
}

function promotion_types(): array
{
    return [
        'bundle' => 'Paquete a precio fijo',
        'percent' => 'Porcentaje de descuento',
    ];
}

/**
 * Every promotion with its category ids, newest first.
 */
function load_promotions(PDO $pdo, bool $activeOnly = false): array
{
    if (!promotion_tables_exist($pdo)) {
        return [];
    }

    $promotions = $pdo->query(
        'SELECT *
         FROM promotions' .
        ($activeOnly ? ' WHERE is_active = 1' : '') . '
         ORDER BY is_active DESC, id DESC'
    )->fetchAll();

    $categories = [];

    foreach ($pdo->query(
        'SELECT pc.promotion_id, pc.category_id, c.name
         FROM promotion_categories pc
         INNER JOIN categories c ON c.id = pc.category_id
         ORDER BY c.sort_order ASC, c.name ASC'
    )->fetchAll() as $row) {
        $categories[(int) $row['promotion_id']][(int) $row['category_id']] = (string) $row['name'];
    }

    foreach ($promotions as $index => $promotion) {
        $promotions[$index]['categories'] = $categories[(int) $promotion['id']] ?? [];
    }

    return $promotions;
}

function find_promotion(PDO $pdo, int $promotionId): ?array
{
    foreach (load_promotions($pdo) as $promotion) {
        if ((int) $promotion['id'] === $promotionId) {
            return $promotion;
        }
    }

    return null;
}

/**
 * "3 por C$1,000.00 en Flats" / "10% en Tacones, Plataformas · primera compra".
 */
function promotion_summary(array $promotion): string
{
    $scope = $promotion['categories'] === []
        ? 'todos los productos'
        : implode(', ', $promotion['categories']);

    $summary = $promotion['type'] === 'bundle'
        ? sprintf('%d por %s en %s', (int) $promotion['bundle_qty'], format_money($promotion['bundle_price']), $scope)
        : sprintf('%s%% de descuento en %s', rtrim(rtrim(number_format((float) $promotion['percent'], 2, '.', ''), '0'), '.'), $scope);

    if ((int) $promotion['first_purchase_only'] === 1) {
        $summary .= ' · solo primera compra';
    }

    return $summary;
}

/**
 * The promotion as the sale form's JavaScript needs it.
 */
function promotion_for_js(array $promotion): array
{
    return [
        'id' => (int) $promotion['id'],
        'name' => (string) $promotion['name'],
        'summary' => promotion_summary($promotion),
        'type' => (string) $promotion['type'],
        'bundle_qty' => (int) ($promotion['bundle_qty'] ?? 0),
        'bundle_price' => (float) ($promotion['bundle_price'] ?? 0),
        'percent' => (float) ($promotion['percent'] ?? 0),
        'first_purchase_only' => (int) $promotion['first_purchase_only'] === 1,
        'starts_on' => $promotion['starts_on'],
        'ends_on' => $promotion['ends_on'],
        'categories' => array_keys($promotion['categories']),
    ];
}

function promotion_is_current(array $promotion, string $date): bool
{
    if ((int) $promotion['is_active'] !== 1) {
        return false;
    }

    if ($promotion['starts_on'] !== null && $date < (string) $promotion['starts_on']) {
        return false;
    }

    return $promotion['ends_on'] === null || $date <= (string) $promotion['ends_on'];
}

/**
 * How much the promotion takes off these lines.
 * $lines: [['category_id' => ?int, 'quantity' => int, 'unit_price' => float], ...]
 */
function promotion_discount_for(array $promotion, array $lines): float
{
    $categoryIds = array_map('intval', array_keys($promotion['categories']));
    $units = [];

    foreach ($lines as $line) {
        if ($categoryIds !== [] && !in_array((int) ($line['category_id'] ?? 0), $categoryIds, true)) {
            continue;
        }

        for ($i = 0; $i < (int) $line['quantity']; $i++) {
            $units[] = (float) $line['unit_price'];
        }
    }

    if ($promotion['type'] === 'percent') {
        return round(array_sum($units) * (float) $promotion['percent'] / 100, 2);
    }

    $size = (int) $promotion['bundle_qty'];

    if ($size < 1 || count($units) < $size) {
        return 0.0;
    }

    rsort($units);

    $bundles = intdiv(count($units), $size);
    $bundled = array_sum(array_slice($units, 0, $bundles * $size));

    return max(0.0, round($bundled - $bundles * (float) $promotion['bundle_price'], 2));
}

/**
 * Digits of a phone, last 8 (Nicaragua numbers), so "+505 8888-1234"
 * and "88881234" match.
 */
function normalize_customer_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    return strlen($digits) > 8 ? substr($digits, -8) : $digits;
}

/**
 * true/false whether the customer already bought (an invoice that was
 * not cancelled), or null when there is no phone or name to check.
 */
function customer_has_previous_sales(PDO $pdo, string $phone, string $name, ?int $excludeSaleId = null): ?bool
{
    $phone = normalize_customer_phone($phone);
    $name = mb_strtolower(trim($name));

    $base = "SELECT customer_phone
             FROM sales
             WHERE doc_type = 'invoice'
               AND status <> 'cancelled'
               AND id <> :exclude";

    if (strlen($phone) >= 7) {
        $statement = $pdo->prepare($base . ' AND customer_phone LIKE :phone');
        $statement->execute([
            'exclude' => (int) $excludeSaleId,
            'phone' => '%' . substr($phone, -4) . '%',
        ]);

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $storedPhone) {
            if (normalize_customer_phone((string) $storedPhone) === $phone) {
                return true;
            }
        }

        return false;
    }

    if ($name === '') {
        return null;
    }

    $statement = $pdo->prepare($base . ' AND LOWER(TRIM(customer_name)) = :name LIMIT 1');
    $statement->execute([
        'exclude' => (int) $excludeSaleId,
        'name' => $name,
    ]);

    return $statement->fetchColumn() !== false;
}

/**
 * Checks the promotion the user accepted against the saved lines and
 * returns ['id', 'name', 'discount'], or throws DomainException.
 */
function resolve_sale_promotion(
    PDO $pdo,
    int $promotionId,
    array $lines,
    string $saleDate,
    string $customerPhone,
    string $customerName,
    ?int $excludeSaleId,
    ?int $keptPromotionId = null
): array {
    $promotion = find_promotion($pdo, $promotionId);

    if ($promotion === null) {
        throw new DomainException('La promoción elegida ya no existe. Quítala o elige otra.');
    }

    // An edit may keep the promotion the sale already had, even if it ended since.
    $kept = $keptPromotionId !== null && $keptPromotionId === $promotionId;

    if (!$kept && !promotion_is_current($promotion, $saleDate)) {
        throw new DomainException(sprintf('La promoción "%s" no está vigente en la fecha de la venta.', $promotion['name']));
    }

    if ((int) $promotion['first_purchase_only'] === 1) {
        $hasPrevious = customer_has_previous_sales($pdo, $customerPhone, $customerName, $excludeSaleId);

        if ($hasPrevious === null) {
            throw new DomainException(sprintf(
                'La promoción "%s" es solo para la primera compra: escribe el teléfono o el nombre del cliente.',
                $promotion['name']
            ));
        }

        if ($hasPrevious) {
            throw new DomainException(sprintf(
                'La promoción "%s" es solo para la primera compra y este cliente ya compró antes.',
                $promotion['name']
            ));
        }
    }

    $discount = promotion_discount_for($promotion, $lines);

    if ($discount <= 0) {
        throw new DomainException(sprintf(
            'La promoción "%s" no aplica a los productos de esta venta.',
            $promotion['name']
        ));
    }

    return [
        'id' => (int) $promotion['id'],
        'name' => (string) $promotion['name'],
        'discount' => $discount,
    ];
}


/*
|--------------------------------------------------------------------------
| Promotion Image (013_promotion_images.sql)
|--------------------------------------------------------------------------
|
| Optional image per promotion, shown in the public catalog.
|
*/

function promotion_images_ready(PDO $pdo): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $pdo->query('SELECT image_path FROM promotions LIMIT 1');
        $ready = true;
    } catch (PDOException) {
        $ready = false;
    }

    return $ready;
}

/**
 * Saves an uploaded image under uploads/promotions and returns its
 * path, or null when no file was chosen. Throws DomainException.
 */
function upload_promotion_image(array $file): ?string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new DomainException('No se pudo subir la imagen (máximo 10 MB).');
    }

    if ((int) $file['size'] > 10 * 1024 * 1024) {
        throw new DomainException('La imagen debe pesar menos de 10 MB.');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new DomainException('La imagen debe ser JPG, PNG o WEBP.');
    }

    $directory = dirname(__DIR__) . '/uploads/promotions';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create uploads/promotions.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];

    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Unable to save promotion image.');
    }

    return 'uploads/promotions/' . $filename;
}

function delete_promotion_image(?string $path): void
{
    $path = ltrim((string) $path, '/');

    if (str_starts_with($path, 'uploads/promotions/') && is_file(dirname(__DIR__) . '/' . $path)) {
        @unlink(dirname(__DIR__) . '/' . $path);
    }
}

/**
 * Active, current promotions that have an image, for the catalog.
 */
function catalog_promotions(PDO $pdo): array
{
    if (!promotion_tables_exist($pdo) || !promotion_images_ready($pdo)) {
        return [];
    }

    $today = date('Y-m-d');

    return array_values(array_filter(
        load_promotions($pdo, true),
        static fn (array $promotion): bool =>
            promotion_is_current($promotion, $today) && (string) ($promotion['image_path'] ?? '') !== ''
    ));
}

