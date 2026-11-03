<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/wholesale-functions.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/promotion-functions.php';

require_admin();

/*
|--------------------------------------------------------------------------
| Catálogo (entrega inmediata)
|--------------------------------------------------------------------------
|
| Printable catalog built at the moment it opens, in the style of the
| "Catalogo entrega inmediata" PDF: cover, Sobre nosotros, one arch
| divider per category and 3x3 pages with "Tallas 6-7-9-10" + name.
| Only sizes with stock are listed and models without stock are left
| out (?todos=1 shows them as "Agotado"). Retail and wholesale prices
| are both shown when they apply, and each model carries a tag for the
| current promotions that really include it (promotion_applies_to()).
| The title carries today's date, so "Guardar como PDF" names the file
| "Catalogo Minorista dd-mm-aaaa.pdf". Cards link to WhatsApp in the PDF.
|
*/

const CATALOG_PER_PAGE = 9;

$includeSoldOut = ($_GET['todos'] ?? '') === '1';
$wholesaleReady = wholesale_columns_exist($pdo);
// Current promotions: each card is tagged only with the ones that apply to it.
$promotions = promotion_tables_exist($pdo) ? current_promotions($pdo) : [];
$autoPrint = isset($_GET['print']);

$months = [
    1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
    'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
];
$now = new DateTimeImmutable('now');
$dateLong = $now->format('j') . ' de ' . $months[(int) $now->format('n')] . ' de ' . $now->format('Y');
$fileTitle = 'Catalogo ' . $now->format('d-m-Y');
$businessName = business_name();
$hasWhatsapp = whatsapp_digits() !== '';

$categories = $pdo->query(
    'SELECT id, name, image
     FROM categories
     WHERE is_active = 1
        AND is_coming_soon = 0
     ORDER BY sort_order ASC, name ASC'
)->fetchAll();

// Same order as the online catalog: manual order first, then the newest.
$products = $pdo->query(
    'SELECT p.*
     FROM products p
     INNER JOIN categories c ON c.id = p.category_id
     WHERE p.is_active = 1
        AND c.is_active = 1
        AND c.is_coming_soon = 0
     ORDER BY (p.sort_order > 0) DESC, p.sort_order ASC, p.created_at DESC, p.id DESC'
)->fetchAll();

// Stock per size, all colors together.
$sizesByProduct = [];

if ($products !== []) {
    $ids = array_map(static fn (array $product): int => (int) $product['id'], $products);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sizeStatement = $pdo->prepare(
        "SELECT product_id, size, stock
         FROM product_variants
         WHERE product_id IN ($placeholders)"
    );
    $sizeStatement->execute($ids);

    foreach ($sizeStatement->fetchAll() as $row) {
        $size = trim((string) $row['size']);

        if ($size !== '') {
            $productId = (int) $row['product_id'];
            $sizesByProduct[$productId][$size] = ($sizesByProduct[$productId][$size] ?? 0) + (int) $row['stock'];
        }
    }
}

function catalog_image_exists(?string $path): bool
{
    $path = trim((string) $path);

    if ($path === '') {
        return false;
    }

    if (preg_match('#^(https?:)?//#i', $path)) {
        return true;
    }

    return is_file(__DIR__ . '/../../' . ltrim($path, '/'));
}

function catalog_image_url(?string $path): string
{
    $path = trim((string) $path);

    if ($path === '' || preg_match('#^(https?:)?//#i', $path)) {
        return $path;
    }

    return APP_BASE_URL . '/' . ltrim($path, '/') . '?v=' . (int) @filemtime(__DIR__ . '/../../' . ltrim($path, '/'));
}

function catalog_price(mixed $price): string
{
    if ($price === null || $price === '') {
        return 'Consultar';
    }

    $value = (float) $price;
    $decimals = abs($value - round($value)) < 0.005 ? 0 : 2;

    return currency_symbol() . number_format($value, $decimals);
}

/**
 * The system saves sizes as 35-40; the store sells them as 5-10.
 * 35 → 5, 36 → 6 … 40 → 10 (whole and half sizes from 34 to 44).
 */
function catalog_display_size(mixed $size): string
{
    $size = trim((string) $size);

    if (is_numeric($size) && (float) $size >= 34 && (float) $size <= 44) {
        $converted = (float) $size - 30;

        return abs($converted - round($converted)) < 0.001 ? (string) (int) round($converted) : rtrim(rtrim(number_format($converted, 1, '.', ''), '0'), '.');
    }

    return $size;
}

/**
 * Sizes with stock, in shoe order: "Tallas 6-7-9-10" / "Talla 8".
 */
function catalog_sizes_label(array $sizes): string
{
    $available = array_values(array_unique(array_map(
        'catalog_display_size',
        array_keys(array_filter($sizes, static fn (int $stock): bool => $stock > 0))
    )));

    if ($available === []) {
        return '';
    }

    usort($available, static function (string $a, string $b): int {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }

        if (is_numeric($a) !== is_numeric($b)) {
            return is_numeric($a) ? -1 : 1;
        }

        return strnatcasecmp($a, $b);
    });

    return (count($available) === 1 ? 'Talla ' : 'Tallas ') . implode('-', $available);
}

/**
 * Wholesale offer of a model as "C$280|6", or null.
 */
function catalog_wholesale_offer(array $item, bool $wholesaleReady): ?string
{
    if (!$wholesaleReady || (int) ($item['is_wholesale'] ?? 0) !== 1 || $item['wholesale_price'] === null) {
        return null;
    }

    return catalog_price($item['wholesale_price']) . '|' . max(1, (int) $item['wholesale_min_qty']);
}

/**
 * The page header shows the retail price and, when it applies, the
 * wholesale price: "Precio C$450 el par · Mayorista C$280 desde 6 pares".
 * When a price differs between the models of a category, each card shows
 * its own price instead.
 */
function catalog_header(array $items, bool $wholesaleReady): array
{
    $retail = array_values(array_unique(array_map(static fn (array $item): string => catalog_price($item['price']), $items)));
    $retailSame = count($retail) === 1 && $retail[0] !== 'Consultar';
    $html = $retailSame ? 'Precio <b>' . e($retail[0]) . '</b> el par' : 'Precio <b>por modelo</b>';

    $offers = array_map(static fn (array $item): ?string => catalog_wholesale_offer($item, $wholesaleReady), $items);
    $withOffer = array_values(array_unique(array_filter($offers)));
    $wholesaleSame = count($withOffer) === 1 && !in_array(null, $offers, true);

    if ($wholesaleSame) {
        [$price, $minimum] = explode('|', $withOffer[0]);
        $html .= ' · Mayorista <b>' . e($price) . '</b> desde <b>' . (int) $minimum . ' pares</b>';
    }

    return [
        'html' => $html,
        'retail_per_card' => !$retailSame,
        'wholesale_per_card' => !$wholesaleSame && $withOffer !== [],
    ];
}

/**
 * Price lines under a model's name, only for what the header does not say.
 */
function catalog_card_prices(array $item, array $header, bool $wholesaleReady): array
{
    $lines = [];

    if ($header['retail_per_card']) {
        $lines[] = catalog_price($item['price']) . ' el par';
    }

    $offer = $header['wholesale_per_card'] ? catalog_wholesale_offer($item, $wholesaleReady) : null;

    if ($offer !== null) {
        [$price, $minimum] = explode('|', $offer);
        $lines[] = 'Mayorista ' . $price . ' desde ' . $minimum . ' pares';
    }

    return $lines;
}

// Group products by category and split each category into pages of nine.
$sections = [];
$hiddenSoldOut = 0;

foreach ($categories as $category) {
    $items = [];

    foreach ($products as $product) {
        if ((int) $product['category_id'] !== (int) $category['id']) {
            continue;
        }

        $product['sizes_label'] = catalog_sizes_label($sizesByProduct[(int) $product['id']] ?? []);

        if ($product['sizes_label'] === '' && !$includeSoldOut) {
            $hiddenSoldOut++;
            continue;
        }

        $items[] = $product;
    }

    if ($items === []) {
        continue;
    }

    $cover = trim((string) ($category['image'] ?? ''));

    if (!catalog_image_exists($cover)) {
        $cover = '';

        foreach ($items as $item) {
            if (catalog_image_exists($item['main_image'])) {
                $cover = (string) $item['main_image'];
                break;
            }
        }
    }

    $sections[] = [
        'name' => (string) $category['name'],
        'cover' => $cover,
        'header' => catalog_header($items, $wholesaleReady),
        'pages' => array_chunk($items, CATALOG_PER_PAGE),
    ];
}

$modelCount = array_sum(array_map(
    static fn (array $section): int => array_sum(array_map('count', $section['pages'])),
    $sections
));

// Fixed artwork; replace these images to change them.
$fixedCover = 'assets/catalog-cover.webp';
$fixedAbout = 'assets/catalog/sobre-nosotros.jpg';
$paper = 'assets/catalog/papel.jpg';
$hasFixedCover = catalog_image_exists($fixedCover);
$hasFixedAbout = catalog_image_exists($fixedAbout);
$hasPaper = catalog_image_exists($paper);

$aboutParagraphs = array_values(array_filter(
    array_map('trim', preg_split('/\R{2,}/', setting('about_text')) ?: []),
    static fn (string $paragraph): bool => $paragraph !== ''
));

function catalog_link(array $query): string
{
    $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');

    return APP_BASE_URL . '/admin/products/catalog.php' . ($query === [] ? '' : '?' . http_build_query($query));
}

$currentQuery = ['todos' => $includeSoldOut ? '1' : null];

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($fileTitle) ?></title>
    <link rel="icon" href="<?= e(business_favicon_url()) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400;0,600;0,700;1,400;1,500;1,600;1,700&family=Pinyon+Script&family=Cormorant+Garamond:wght@600&display=swap"
        rel="stylesheet">
    <style>
        @page {
            size: 612pt 792pt;
            margin: 0;
        }

        :root {
            --brown: #4a2a1d;
            --soft: #9a7462;
            --line: #a97b63;
            --script: #8b5a45;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
        }

        body {
            background: #8f8478;
            color: var(--brown);
            font-family: "Instrument Sans", Arial, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .catalog-toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 20px;
            background: #1d1a18;
            color: #fff;
            font-family: Arial, sans-serif;
            font-size: 13px;
        }

        .catalog-toolbar small {
            display: block;
            margin-top: 3px;
            color: #cfc6bd;
        }

        .catalog-toolbar-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .catalog-toolbar a,
        .catalog-toolbar button {
            border: 1px solid #fff;
            padding: 10px 16px;
            background: transparent;
            color: #fff;
            font: inherit;
            font-size: 12px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            cursor: pointer;
        }

        .catalog-toolbar button {
            background: #fff;
            color: #1d1a18;
            font-weight: 700;
        }

        .catalog-pages {
            display: grid;
            justify-content: center;
            gap: 24px;
            padding: 24px 12px 60px;
        }

        .page {
            position: relative;
            width: 612pt;
            height: 792pt;
            overflow: hidden;
            background: <?= $hasPaper ? 'url(' . e(catalog_image_url($paper)) . ') center / cover no-repeat,' : '' ?> #ebe4d8;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            page-break-after: always;
            break-after: page;
        }

        .page-image img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .script {
            font-family: "Pinyon Script", serif;
            font-weight: 400;
        }

        /* About (text version when there is no fixed image) */
        .about {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 70pt 80pt;
            text-align: center;
        }

        .about h2 {
            margin: 0 0 22pt;
            font-family: "Cormorant Garamond", serif;
            font-size: 34pt;
            font-weight: 600;
        }

        .about h2 .script {
            margin-right: 4pt;
            font-size: 46pt;
        }

        .about p {
            margin: 0 0 10pt;
            color: var(--soft);
            font-size: 11pt;
            font-style: italic;
            line-height: 1.55;
        }

        /* Category divider */
        .divider-arch {
            position: absolute;
            top: 33pt;
            left: 10pt;
            right: 10pt;
            bottom: 64pt;
            overflow: hidden;
            border-radius: 296pt 296pt 0 0;
            background: #d8c6ae;
        }

        .divider-arch img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .divider-line {
            position: absolute;
            top: 55pt;
            left: 30pt;
            right: 30pt;
            bottom: 84pt;
            border: 1pt solid var(--line);
            border-radius: 276pt 276pt 0 0;
        }

        .divider-name {
            position: absolute;
            right: 70pt;
            bottom: 112pt;
            color: var(--script);
            font-size: 58pt;
            line-height: 1;
            text-shadow: 0 0 18pt rgba(255, 245, 235, 0.55);
        }

        .divider-note {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 22pt;
            color: var(--soft);
            font-size: 13pt;
            font-style: italic;
            text-align: center;
        }

        .divider-note b {
            color: var(--brown);
        }

        /* Product pages */
        .grid-header {
            position: absolute;
            top: 30pt;
            left: 30pt;
            right: 30pt;
            color: var(--brown);
            font-size: 16pt;
            font-style: italic;
            text-align: center;
        }

        .grid-header b {
            font-weight: 700;
        }

        .product-grid {
            position: absolute;
            top: 72pt;
            left: 47pt;
            display: grid;
            grid-template-columns: repeat(3, 151pt);
            grid-auto-rows: 236pt;
            column-gap: 32pt;
        }

        .product-card {
            display: block;
            text-align: center;
        }

        .product-arch {
            width: 151pt;
            height: 180pt;
            padding: 4pt;
            border: 1pt solid var(--line);
            border-radius: 76pt 76pt 0 0;
        }

        .product-arch img,
        .product-arch-empty {
            display: block;
            width: 100%;
            height: 100%;
            border-radius: 72pt 72pt 0 0;
            object-fit: cover;
        }

        .product-arch-empty {
            display: grid;
            place-items: center;
            background: #d8c6ae;
            color: #8d7563;
            font-size: 12pt;
        }

        .product-sizes {
            margin-top: 6pt;
            color: var(--soft);
            font-size: 12.5pt;
            font-style: italic;
            line-height: 1.1;
        }

        .product-name {
            color: var(--brown);
            font-size: 12.5pt;
            font-style: italic;
            font-weight: 700;
            line-height: 1.15;
        }

        .product-price {
            margin-top: 1pt;
            color: var(--soft);
            font-size: 9pt;
            font-style: italic;
        }

        /* Pages whose cards carry extra price lines get shorter arches. */
        .compact .product-arch {
            height: 162pt;
        }

        .compact .product-price {
            font-size: 8.5pt;
            line-height: 1.15;
        }

        .product-promo {
            display: inline-block;
            margin-top: 2pt;
            padding: 0.5pt 6pt;
            border-radius: 8pt;
            background: rgba(156, 104, 68, 0.16);
            color: var(--script);
            font-size: 7.5pt;
            font-weight: 600;
        }

        .sold-out .product-arch img {
            filter: grayscale(0.7);
            opacity: 0.6;
        }

        .empty-state {
            max-width: 520px;
            margin: 60px auto;
            padding: 30px;
            background: #fff;
            text-align: center;
        }

        @media print {
            body {
                background: none;
            }

            .catalog-toolbar {
                display: none;
            }

            .catalog-pages {
                display: block;
                padding: 0;
            }

            .page {
                box-shadow: none;
            }

            .page:last-child {
                page-break-after: auto;
                break-after: auto;
            }
        }

        @media screen and (max-width: 860px) {
            .catalog-pages {
                justify-content: start;
                overflow-x: auto;
            }

            .catalog-toolbar-actions {
                width: 100%;
            }

            .catalog-toolbar-actions > * {
                flex: 1;
                text-align: center;
            }

            .page {
                zoom: 0.7;
            }
        }

        @media screen and (max-width: 560px) {
            .page {
                zoom: 0.44;
            }
        }
    </style>
</head>

<body>
    <div class="catalog-toolbar">
        <div>
            <strong>Catálogo · <?= e($dateLong) ?> · <?= (int) $modelCount ?> modelos</strong>
            <small>
                Tallas con stock de este momento.
                <?php if ($hiddenSoldOut > 0): ?>
                    <?= (int) $hiddenSoldOut ?> modelo<?= $hiddenSoldOut === 1 ? '' : 's' ?> sin stock no aparece<?= $hiddenSoldOut === 1 ? '' : 'n' ?>.
                <?php endif; ?>
                En Destino elige "Guardar como PDF".
            </small>
        </div>
        <div class="catalog-toolbar-actions">
            <a href="<?= e(APP_BASE_URL) ?>/admin/products/">← Productos</a>
            <a href="<?= e(catalog_link(['todos' => $includeSoldOut ? null : '1'] + $currentQuery)) ?>">
                <?= $includeSoldOut ? 'Solo con stock' : 'Incluir agotados' ?>
            </a>
            <button type="button" onclick="window.print()">Descargar PDF</button>
        </div>
    </div>

    <?php if ($sections === []): ?>
        <div class="empty-state">
            No hay productos activos con stock en categorías visibles.
        </div>
    <?php else: ?>
        <div class="catalog-pages">

            <?php if ($hasFixedCover): ?>
                <section class="page page-image">
                    <img src="<?= e(catalog_image_url($fixedCover)) ?>" alt="Catálogo <?= e($businessName) ?>">
                </section>
            <?php endif; ?>

            <?php if ($hasFixedAbout): ?>
                <section class="page page-image">
                    <img src="<?= e(catalog_image_url($fixedAbout)) ?>" alt="Sobre nosotros">
                </section>
            <?php elseif ($aboutParagraphs !== []): ?>
                <section class="page about">
                    <h2><span class="script">Sobre</span>Nosotros</h2>
                    <?php foreach ($aboutParagraphs as $paragraph): ?>
                        <p><?= nl2br(e($paragraph)) ?></p>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php foreach ($sections as $section): ?>
                <section class="page">
                    <div class="divider-arch">
                        <?php if ($section['cover'] !== ''): ?>
                            <img src="<?= e(catalog_image_url($section['cover'])) ?>" alt="">
                        <?php endif; ?>
                    </div>
                    <div class="divider-line"></div>
                    <div class="divider-name script"<?= mb_strlen($section['name']) > 8 ? ' style="font-size: ' . max(34, 58 - 3 * (mb_strlen($section['name']) - 8)) . 'pt"' : '' ?>><?= e($section['name']) ?></div>
                    <div class="divider-note">Estilos disponibles para <b>entrega inmediata</b></div>
                </section>

                <?php foreach ($section['pages'] as $pageItems): ?>
                    <?php
                    $compact = $section['header']['retail_per_card'] || $section['header']['wholesale_per_card'];
                    ?>
                    <section class="page<?= $compact ? ' compact' : '' ?>">
                        <div class="grid-header"><?= $section['header']['html'] ?></div>
                        <div class="product-grid">
                            <?php foreach ($pageItems as $product): ?>
                                <?php
                                $code = trim((string) $product['code']);
                                $soldOut = $product['sizes_label'] === '';
                                $tag = $hasWhatsapp && !$soldOut ? 'a' : 'div';
                                $message = 'Hola ' . $businessName . ', quiero pedir el modelo ' . $product['name']
                                    . ($code !== '' ? ' (' . $code . ')' : '') . '. Mi talla es: ';
                                ?>
                                <<?= $tag ?> class="product-card<?= $soldOut ? ' sold-out' : '' ?>"<?php if ($tag === 'a'): ?> href="<?= e(whatsapp_link($message)) ?>" target="_blank" rel="noopener"<?php endif; ?>>
                                    <div class="product-arch">
                                        <?php if (catalog_image_exists($product['main_image'])): ?>
                                            <img src="<?= e(catalog_image_url((string) $product['main_image'])) ?>" alt="<?= e($product['name']) ?>">
                                        <?php else: ?>
                                            <div class="product-arch-empty"><?= e($businessName) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="product-sizes"><?= $soldOut ? 'Agotado' : e($product['sizes_label']) ?></div>
                                    <div class="product-name"><?= e($product['name']) ?></div>
                                    <?php foreach (catalog_card_prices($product, $section['header'], $wholesaleReady) as $priceLine): ?>
                                        <div class="product-price"><?= e($priceLine) ?></div>
                                    <?php endforeach; ?>
                                    <?php foreach ($promotions as $promotion): ?>
                                        <?php if (!$soldOut && promotion_applies_to($promotion, (int) $product['id'], (int) $product['category_id'])): ?>
                                            <div class="product-promo">Promo <?= e(promotion_short_label($promotion)) ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </<?= $tag ?>>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php endforeach; ?>

        </div>
    <?php endif; ?>

    <?php if ($autoPrint && $sections !== []): ?>
        <script>
            window.addEventListener('load', function () {
                (document.fonts ? document.fonts.ready : Promise.resolve()).then(function () {
                    window.print();
                });
            });
        </script>
    <?php endif; ?>
</body>

</html>
