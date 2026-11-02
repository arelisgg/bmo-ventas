<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Sale Options
|--------------------------------------------------------------------------
*/

function sale_channels(): array
{
    return [
        'whatsapp' => 'WhatsApp',
        'instagram' => 'Instagram',
        'store' => 'Tienda',
        'wholesale' => 'Mayorista',
        'other' => 'Otro',
    ];
}

function sale_payment_methods(): array
{
    return [
        'cash' => 'Efectivo',
        'transfer' => 'Transferencia',
        'card' => 'Tarjeta',
        'other' => 'Otro',
    ];
}

function sale_statuses(): array
{
    return [
        'paid' => 'Pagada',
        'pending' => 'Pendiente de pago',
        'cancelled' => 'Cancelada',
    ];
}

function sale_item_sources(): array
{
    return [
        'inventory' => 'Del inventario',
        'workshop' => 'Por encargo (sin inventario)',
    ];
}

function sale_option_label(
    array $options,
    ?string $key
): string {
    return $options[(string) $key] ?? (string) $key;
}


/*
|--------------------------------------------------------------------------
| Money
|--------------------------------------------------------------------------
*/

function format_money(mixed $amount): string
{
    return currency_symbol() . number_format(
        (float) $amount,
        2
    );
}


/*
|--------------------------------------------------------------------------
| Parse Money Input
|--------------------------------------------------------------------------
|
| Accepts "1250", "1,250.50" or "1250.5". Returns null when invalid.
|
*/

function parse_money_input(mixed $value): ?float
{
    if (!is_scalar($value)) {
        return null;
    }

    $value = str_replace(
        [',', currency_symbol(), 'C$', 'US$', '$', ' '],
        '',
        trim((string) $value)
    );

    if ($value === '') {
        return 0.0;
    }

    if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
        return null;
    }

    return round((float) $value, 2);
}


/*
|--------------------------------------------------------------------------
| Sale Number
|--------------------------------------------------------------------------
*/

function sale_number_for_id(int $saleId): string
{
    return 'V-' . str_pad(
        (string) $saleId,
        5,
        '0',
        STR_PAD_LEFT
    );
}


/*
|--------------------------------------------------------------------------
| Restore Stock For A Sale
|--------------------------------------------------------------------------
|
| Used when a sale is cancelled. If the original variant no longer
| exists, it tries to find the same product + color + size.
| Must be called inside a transaction.
|
*/

function restore_sale_stock(
    PDO $pdo,
    int $saleId
): void {
    $itemsStatement = $pdo->prepare(
        "SELECT
            product_id,
            variant_id,
            color_name,
            size,
            quantity
         FROM sale_items
         WHERE
            sale_id = :sale_id
            AND source = 'inventory'"
    );

    $itemsStatement->execute([
        'sale_id' => $saleId,
    ]);

    $restoreById = $pdo->prepare(
        'UPDATE product_variants
         SET stock = stock + :quantity
         WHERE id = :id'
    );

    $restoreByAttributes = $pdo->prepare(
        'UPDATE product_variants
         SET stock = stock + :quantity
         WHERE
            product_id = :product_id
            AND color_name = :color_name
            AND size = :size
         LIMIT 1'
    );

    foreach ($itemsStatement->fetchAll() as $item) {
        $quantity = (int) $item['quantity'];

        if ($item['variant_id'] !== null) {
            $restoreById->execute([
                'quantity' => $quantity,
                'id' => (int) $item['variant_id'],
            ]);

            if ($restoreById->rowCount() > 0) {
                continue;
            }
        }

        if ($item['product_id'] !== null) {
            $restoreByAttributes->execute([
                'quantity' => $quantity,
                'product_id' => (int) $item['product_id'],
                'color_name' => $item['color_name'],
                'size' => $item['size'],
            ]);
        }
    }
}


/*
|--------------------------------------------------------------------------
| Sales Tables Exist
|--------------------------------------------------------------------------
|
| Lets the dashboard keep working before the migration is run.
|
*/

function sales_tables_exist(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM sales LIMIT 1');
        $pdo->query('SELECT source, image_path FROM sale_items LIMIT 1');
        $pdo->query('SELECT doc_type FROM sales LIMIT 1');

        return true;
    } catch (PDOException) {
        return false;
    }
}


/*
|--------------------------------------------------------------------------
| Sale Item Reference Photo
|--------------------------------------------------------------------------
|
| Optional photo for 'por encargo' items, used as reference for the
| workshop. Returns null when no file was chosen.
|
*/

function upload_sale_item_image(array $file): ?string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new DomainException(
            'La foto de referencia es demasiado grande.'
        );
    }

    if (
        $error !== UPLOAD_ERR_OK ||
        !is_uploaded_file((string) ($file['tmp_name'] ?? ''))
    ) {
        throw new DomainException(
            'No se pudo subir la foto de referencia.'
        );
    }

    if ((int) $file['size'] > 10 * 1024 * 1024) {
        throw new DomainException(
            'La foto de referencia debe pesar menos de 10 MB.'
        );
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

    if (
        !isset($allowed[$mime]) ||
        @getimagesize($file['tmp_name']) === false
    ) {
        throw new DomainException(
            'La foto de referencia debe ser JPG, PNG o WEBP.'
        );
    }

    $directory = dirname(__DIR__) . '/uploads/sales';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create uploads/sales.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];

    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Unable to save sale item image.');
    }

    return 'uploads/sales/' . $filename;
}

function delete_sale_item_image(?string $imagePath): void
{
    $imagePath = ltrim((string) $imagePath, '/');

    if (!str_starts_with($imagePath, 'uploads/sales/')) {
        return;
    }

    $absolutePath = dirname(__DIR__) . '/' . $imagePath;

    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

/*
|--------------------------------------------------------------------------
| Made-To-Order Products
|--------------------------------------------------------------------------
|
| 'Por encargo' items are also saved in products, with the category,
| description and visibility chosen in the sale, and never with stock.
| A code that already exists reuses that product instead of creating a
| new one. Earlier ones are found through their 'workshop' sale items.
|
*/

function made_to_order_visibilities(): array
{
    return [
        'hidden' => 'Oculto del catálogo',
        'active' => 'Visible en el catálogo',
        'featured' => 'Visible y destacado',
        'new' => 'Visible y nuevo',
    ];
}

function save_made_to_order_product(PDO $pdo, array $item): array
{
    $product = false;

    if ($item['custom_code'] !== '') {
        $statement = $pdo->prepare(
            'SELECT id, code, main_image
             FROM products
             WHERE code = :code
             LIMIT 1
             FOR UPDATE'
        );

        $statement->execute([
            'code' => $item['custom_code'],
        ]);

        $product = $statement->fetch();
    }

    if ($product) {
        $productId = (int) $product['id'];
        $code = (string) $product['code'];

        if (empty($product['main_image']) && $item['image_path'] !== null) {
            $pdo->prepare(
                'UPDATE products
                 SET main_image = :main_image
                 WHERE id = :id'
            )->execute([
                'main_image' => $item['image_path'],
                'id' => $productId,
            ]);
        }
    } else {
        $categoryCheck = $pdo->prepare(
            'SELECT id
             FROM categories
             WHERE
                id = :id
                AND is_active = 1
             LIMIT 1'
        );

        $categoryCheck->execute([
            'id' => (int) $item['custom_category_id'],
        ]);

        if ($item['custom_category_id'] === null || $categoryCheck->fetchColumn() === false) {
            throw new DomainException(sprintf(
                'Elige la categoría de "%s".',
                $item['custom_name']
            ));
        }

        $code = $item['custom_code'] !== ''
            ? $item['custom_code']
            : 'ENC-TMP-' . bin2hex(random_bytes(8));

        $visibility = $item['custom_visibility'];

        $pdo->prepare(
            'INSERT INTO products
            (
                category_id,
                code,
                name,
                slug,
                description,
                price,
                main_image,
                is_active,
                is_featured,
                is_new
            )
            VALUES
            (
                :category_id,
                :code,
                :name,
                :slug,
                :description,
                :price,
                :main_image,
                :is_active,
                :is_featured,
                :is_new
            )'
        )->execute([
            'category_id' => $item['custom_category_id'],
            'code' => $code,
            'name' => $item['custom_name'],
            'slug' => generate_unique_product_slug($pdo, $item['custom_name']),
            'description' => $item['custom_description'] !== '' ? $item['custom_description'] : null,
            'price' => $item['unit_price'],
            'main_image' => $item['image_path'],
            'is_active' => $visibility === 'hidden' ? 0 : 1,
            'is_featured' => $visibility === 'featured' ? 1 : 0,
            'is_new' => $visibility === 'new' ? 1 : 0,
        ]);

        $productId = (int) $pdo->lastInsertId();

        if ($item['custom_code'] === '') {
            $code = sprintf('ENC-%05d', $productId);

            $pdo->prepare(
                'UPDATE products
                 SET code = :code
                 WHERE id = :id'
            )->execute([
                'code' => $code,
                'id' => $productId,
            ]);
        }
    }

    // Keep the requested color and size on the product, always with stock 0.
    if ($item['custom_color'] !== '' && $item['custom_size'] !== '') {
        $pdo->prepare(
            'INSERT IGNORE INTO product_variants
            (
                product_id,
                color_name,
                size,
                stock
            )
            VALUES
            (
                :product_id,
                :color_name,
                :size,
                0
            )'
        )->execute([
            'product_id' => $productId,
            'color_name' => $item['custom_color'],
            'size' => $item['custom_size'],
        ]);
    }

    return [
        'id' => $productId,
        'code' => $code,
    ];
}

/*
| Models that can be sold 'por encargo': every catalog model (with or
| without stock) plus the products created by earlier encargos.
| is_previous_order = 1 marks the ones that were already ordered.
*/
function made_to_order_products(PDO $pdo): array
{
    return $pdo->query(
        'SELECT
            p.*,
            c.name AS category_name,
            EXISTS (
                SELECT 1
                FROM sale_items si
                WHERE
                    si.product_id = p.id
                    AND si.source = \'workshop\'
            ) AS is_previous_order,
            (
                SELECT COALESCE(SUM(pv.stock), 0)
                FROM product_variants pv
                WHERE pv.product_id = p.id
            ) AS stock_total
         FROM products p
         LEFT JOIN categories c
            ON c.id = p.category_id
         ORDER BY
            c.sort_order IS NULL ASC,
            c.sort_order ASC,
            c.name ASC,
            p.name ASC
         LIMIT 1000'
    )->fetchAll();
}
/*
|--------------------------------------------------------------------------
| Document Types
|--------------------------------------------------------------------------
|
| Only an invoice ('invoice') is a real sale: it takes stock and counts
| in the sales and finance totals. Quotes and proformas never do.
|
*/

function sale_doc_types(): array
{
    return [
        'invoice' => 'Factura de venta',
        'proforma' => 'Prefactura',
    ];
}

function sale_doc_type_titles(): array
{
    return [
        'invoice' => 'FACTURA',
        'proforma' => 'PREFACTURA',
        'quote' => 'COTIZACIÓN',
    ];
}

function sale_number_for(string $docType, int $saleId): string
{
    $prefixes = [
        'invoice' => 'V-',
        'proforma' => 'PRE-',
        'quote' => 'COT-',
    ];

    return ($prefixes[$docType] ?? 'V-') . str_pad((string) $saleId, 5, '0', STR_PAD_LEFT);
}

function sale_consumes_stock(string $docType, string $status): bool
{
    return $docType === 'invoice' && $status !== 'cancelled';
}


/*
|--------------------------------------------------------------------------
| Load A Sale Into The Sale Form (edit)
|--------------------------------------------------------------------------
|
| Returns ['sale' => row, 'form' => fields, 'items' => form rows,
| 'returned_stock' => [variant_id => qty]] or null. returned_stock is
| what the sale holds today, so the picker can offer it again.
|
*/

function load_sale_for_edit(PDO $pdo, int $saleId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM sales WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $saleId]);
    $sale = $statement->fetch();

    if (!$sale) {
        return null;
    }

    try {
        $pdo->query('SELECT cost_price FROM products LIMIT 1');
        $costColumn = 'p.cost_price';
    } catch (PDOException) {
        $costColumn = 'NULL AS cost_price';
    }

    $itemsStatement = $pdo->prepare(
        'SELECT si.*, p.category_id, p.description AS product_description,
            p.is_active, p.is_featured, p.is_new, ' . $costColumn . '
         FROM sale_items si
         LEFT JOIN products p ON p.id = si.product_id
         WHERE si.sale_id = :sale_id
         ORDER BY si.id ASC'
    );
    $itemsStatement->execute(['sale_id' => $saleId]);

    $money = static fn (mixed $value): string =>
        $value === null ? '' : number_format((float) $value, 2, '.', '');

    $consumes = sale_consumes_stock((string) $sale['doc_type'], (string) $sale['status']);
    $items = [];
    $returnedStock = [];

    foreach ($itemsStatement->fetchAll() as $item) {
        if ($item['source'] === 'workshop') {
            $visibility = 'hidden';

            if ((int) $item['is_active'] === 1) {
                $visibility = (int) $item['is_featured'] === 1
                    ? 'featured'
                    : ((int) $item['is_new'] === 1 ? 'new' : 'active');
            }

            $items[] = [
                'source' => 'workshop',
                'variant_id' => '',
                'quantity' => (string) $item['quantity'],
                'unit_price' => $money($item['unit_price']),
                'custom_name' => (string) $item['product_name'],
                'custom_code' => (string) $item['product_code'],
                'custom_color' => (string) $item['color_name'],
                'custom_size' => (string) $item['size'],
                'custom_category' => (string) ($item['category_id'] ?? ''),
                'custom_visibility' => $visibility,
                'custom_description' => (string) ($item['product_description'] ?? ''),
                'custom_cost' => $money($item['cost_price']),
                'existing_image' => (string) ($item['image_path'] ?? ''),
            ];

            continue;
        }

        $variantId = (int) $item['variant_id'];

        if ($consumes && $variantId > 0) {
            $returnedStock[$variantId] = ($returnedStock[$variantId] ?? 0) + (int) $item['quantity'];
        }

        $items[] = [
            'source' => 'inventory',
            'variant_id' => $variantId > 0 ? (string) $variantId : '',
            'quantity' => (string) $item['quantity'],
            'unit_price' => $money($item['unit_price']),
        ];
    }

    return [
        'sale' => $sale,
        'form' => [
            'doc_type' => (string) $sale['doc_type'],
            'customer_name' => (string) ($sale['customer_name'] ?? ''),
            'customer_phone' => (string) ($sale['customer_phone'] ?? ''),
            'channel' => (string) $sale['channel'],
            'payment_method' => (string) $sale['payment_method'],
            'status' => (string) $sale['status'],
            // The form's discount is the manual one; the promotion is added on save.
            'discount' => ($manual = (float) $sale['discount'] - (float) ($sale['promotion_discount'] ?? 0)) > 0
                ? $money($manual)
                : '',
            'promotion_id' => (string) ($sale['promotion_id'] ?? ''),
            'notes' => (string) ($sale['notes'] ?? ''),
            'sold_at' => date('Y-m-d\TH:i', strtotime((string) $sale['sold_at'])),
        ],
        'items' => $items,
        'returned_stock' => $returnedStock,
    ];
}

/*
| A kept photo path must be one of ours.
*/
function sale_existing_image_path(string $path): ?string
{
    $path = ltrim($path, '/');

    return preg_match('#^uploads/sales/[a-f0-9]+\.(jpg|png|webp)$#', $path) === 1
        ? $path
        : null;
}

/*
| Takes the stock of a sale's inventory items, e.g. when a quote becomes
| an invoice. Throws DomainException when a size ran out. Must be called
| inside a transaction.
*/
function take_sale_stock(PDO $pdo, int $saleId): void
{
    $items = $pdo->prepare(
        "SELECT variant_id, product_name, color_name, size, quantity
         FROM sale_items
         WHERE sale_id = :sale_id AND source = 'inventory'"
    );
    $items->execute(['sale_id' => $saleId]);

    $needed = [];

    foreach ($items->fetchAll() as $item) {
        $key = (int) $item['variant_id'];
        $needed[$key] = ($needed[$key] ?? ['quantity' => 0] + $item);
        $needed[$key]['quantity'] += (int) $item['quantity'];
    }

    $lock = $pdo->prepare('SELECT stock FROM product_variants WHERE id = :id FOR UPDATE');
    $decrement = $pdo->prepare('UPDATE product_variants SET stock = stock - :quantity WHERE id = :id');

    foreach ($needed as $variantId => $item) {
        $lock->execute(['id' => $variantId]);
        $stock = $lock->fetchColumn();

        if ($variantId === 0 || $stock === false || (int) $stock < $item['quantity']) {
            throw new DomainException(sprintf(
                'No hay suficiente stock de %s (%s, talla %s).',
                $item['product_name'],
                $item['color_name'],
                $item['size']
            ));
        }

        $decrement->execute(['quantity' => $item['quantity'], 'id' => $variantId]);
    }
}
