<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

require_admin();

/*
|--------------------------------------------------------------------------
| Catálogo minorista
|--------------------------------------------------------------------------
|
| Printable retail catalog built from the products visible in the store.
| The page title carries today's date, so "Guardar como PDF" names the
| file "Catalogo Minorista dd-mm-aaaa.pdf".
|
*/

const CATALOG_PER_PAGE = 9;
define('CATALOG_WHATSAPP', setting('whatsapp_number'));
define('CATALOG_INSTAGRAM', setting('instagram_user') !== '' ? '@' . social_handle('instagram_user') : '');

$months = [
    1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
    'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
];
$now = new DateTimeImmutable('now');
$dateLong = $now->format('j') . ' de ' . $months[(int) $now->format('n')] . ' de ' . $now->format('Y');
$fileTitle = 'Catalogo Minorista ' . $now->format('d-m-Y');

$categories = $pdo->query(
    'SELECT id, name, image
     FROM categories
     WHERE is_active = 1
        AND is_coming_soon = 0
     ORDER BY sort_order ASC, name ASC'
)->fetchAll();

$products = $pdo->query(
    'SELECT p.id, p.category_id, p.code, p.name, p.price, p.main_image, p.is_featured
     FROM products p
     INNER JOIN categories c ON c.id = p.category_id
     WHERE p.is_active = 1
        AND c.is_active = 1
        AND c.is_coming_soon = 0
     ORDER BY p.sort_order ASC, p.created_at DESC, p.id DESC'
)->fetchAll();

$sizesByProduct = [];

if ($products !== []) {
    $ids = array_map(static fn (array $product): int => (int) $product['id'], $products);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sizeStatement = $pdo->prepare(
        "SELECT product_id, size
         FROM product_variants
         WHERE product_id IN ($placeholders)"
    );
    $sizeStatement->execute($ids);

    foreach ($sizeStatement->fetchAll() as $row) {
        $size = trim((string) $row['size']);

        if ($size !== '') {
            $sizesByProduct[(int) $row['product_id']][$size] = true;
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

    return APP_BASE_URL . '/' . ltrim($path, '/');
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

function catalog_sizes(array $sizes): string
{
    $sizes = array_keys($sizes);

    if ($sizes === []) {
        return '';
    }

    $numeric = array_filter($sizes, static fn (string $size): bool => is_numeric($size));

    if (count($numeric) === count($sizes)) {
        sort($numeric, SORT_NUMERIC);
        $first = reset($numeric);
        $last = end($numeric);

        return $first === $last ? 'Talla ' . $first : 'Tallas ' . $first . ' a ' . $last;
    }

    natsort($sizes);

    return 'Tallas ' . implode(', ', $sizes);
}

// Group products by category and split each category into pages of nine.
$sections = [];

foreach ($categories as $category) {
    $items = array_values(array_filter(
        $products,
        static fn (array $product): bool => (int) $product['category_id'] === (int) $category['id']
    ));

    if ($items === []) {
        continue;
    }

    $prices = array_filter(array_map(
        static fn (array $product) => $product['price'] === null ? null : (float) $product['price'],
        $items
    ), static fn ($price): bool => $price !== null);

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
        'from' => $prices === [] ? null : min($prices),
        'pages' => array_chunk($items, CATALOG_PER_PAGE),
    ];
}

$coverImage = '';

foreach ([true, false] as $featuredOnly) {
    foreach ($products as $product) {
        if (catalog_image_exists($product['main_image']) && (!$featuredOnly || (int) $product['is_featured'] === 1)) {
            $coverImage = (string) $product['main_image'];
            break 2;
        }
    }
}

$logo = business_logo_url();
$aboutParagraphs = array_values(array_filter(
    array_map('trim', preg_split('/\R{2,}/', setting('about_text')) ?: []),
    static fn (string $paragraph): bool => $paragraph !== ''
));

// Same cover as the wholesale catalog; replace this image to change it.
$fixedCover = 'assets/catalog-cover.webp';
$hasFixedCover = catalog_image_exists($fixedCover);
$autoPrint = isset($_GET['print']);

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
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:ital,wght@0,400;0,500;0,600;0,800;1,400&family=Pinyon+Script&display=swap"
        rel="stylesheet">
    <style>
        @page {
            size: 8in 10in;
            margin: 0;
        }

        :root {
            --paper: #e7dfcd;
            --paper-dark: #ddd2bb;
            --brown: #3b2219;
            --brown-soft: #8b5a45;
            --line: #9a6a54;
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
            font-family: "Inter", Arial, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
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
            font-size: 0.85rem;
        }

        .catalog-toolbar a,
        .catalog-toolbar button {
            border: 1px solid #fff;
            padding: 10px 16px;
            background: transparent;
            color: #fff;
            font: inherit;
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-decoration: none;
            text-transform: uppercase;
            cursor: pointer;
        }

        .catalog-toolbar button {
            background: #fff;
            color: #1d1a18;
        }

        .catalog-toolbar-actions {
            display: flex;
            gap: 10px;
        }

        .catalog-toolbar small {
            display: block;
            margin-top: 3px;
            color: #cfc6bd;
        }

        .catalog-pages {
            display: grid;
            justify-content: center;
            gap: 24px;
            padding: 24px 12px 60px;
        }

        .page {
            position: relative;
            width: 8in;
            height: 10in;
            overflow: hidden;
            background:
                radial-gradient(ellipse at 12% 8%, rgba(255, 255, 255, 0.55), transparent 42%),
                radial-gradient(ellipse at 92% 88%, rgba(255, 255, 255, 0.4), transparent 38%),
                radial-gradient(ellipse at 85% 15%, rgba(176, 150, 120, 0.18), transparent 35%),
                var(--paper);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            page-break-after: always;
            break-after: page;
        }

        .script {
            font-family: "Pinyon Script", "Cormorant Garamond", serif;
            font-weight: 400;
        }

        /* ---------- Cover ---------- */

        .cover-frame {
            position: absolute;
            inset: 0.85in 0.55in 0.55in;
            overflow: hidden;
            background: #c9b49a;
        }

        .cover-frame img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .cover-frame::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(40, 22, 14, 0.15) 0%, rgba(40, 22, 14, 0) 40%, rgba(40, 22, 14, 0.45) 100%);
        }

        .cover-logo {
            position: absolute;
            top: 1.35in;
            left: 0;
            right: 0;
            z-index: 2;
            text-align: center;
        }

        .cover-logo img {
            width: 3.1in;
            filter: brightness(0) invert(1) drop-shadow(0 2px 8px rgba(0, 0, 0, 0.25));
        }

        .cover-logo-dark img {
            filter: none;
        }

        .cover-seal {
            position: absolute;
            top: 0.35in;
            right: 0.3in;
            z-index: 3;
            display: grid;
            place-items: center;
            width: 1.5in;
            height: 1.5in;
            border-radius: 50%;
            background: #efeae2;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
            color: var(--brown);
            text-align: center;
            font-size: 0.11in;
            font-weight: 600;
            letter-spacing: 0.14em;
            line-height: 1.5;
            text-transform: uppercase;
        }

        .cover-seal strong {
            display: block;
            font-family: "Cormorant Garamond", serif;
            font-size: 0.22in;
            letter-spacing: 0.06em;
        }

        .cover-title {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0.95in;
            z-index: 2;
            color: #fff;
            text-align: center;
        }

        .cover-title .script {
            display: block;
            font-size: 1.15in;
            line-height: 0.9;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .cover-title .cover-kind {
            display: block;
            margin-top: 0.08in;
            font-size: 0.42in;
            font-weight: 600;
            letter-spacing: 0.04em;
        }

        .cover-title .cover-date {
            display: inline-block;
            margin-top: 0.14in;
            padding: 0.06in 0.2in;
            border: 1px solid rgba(255, 255, 255, 0.75);
            font-size: 0.14in;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .cover-plain .cover-frame {
            background: var(--paper-dark);
        }

        .cover-plain .cover-frame::after {
            content: none;
        }

        .cover-plain .cover-title {
            color: var(--brown);
        }

        .cover-plain .cover-title .cover-date {
            border-color: var(--brown);
        }

        .cover-exact img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* ---------- About ---------- */

        .about {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0.9in 0.85in;
            text-align: center;
        }

        .page-heading {
            margin: 0 0 0.45in;
            font-size: 0.62in;
            font-weight: 600;
            line-height: 1;
        }

        .page-heading .script {
            margin-right: 0.08in;
            font-size: 0.85in;
        }

        .about p {
            margin: 0 0 0.18in;
            color: var(--brown-soft);
            font-size: 0.2in;
            font-style: italic;
            line-height: 1.55;
        }

        .about p strong {
            font-style: italic;
        }

        .about-list {
            display: grid;
            gap: 0.14in;
            margin: 0.35in 0 0;
            padding: 0;
            list-style: none;
            color: var(--brown-soft);
            font-size: 0.19in;
            font-style: italic;
        }

        .about-list b {
            color: var(--brown);
            font-style: normal;
        }

        /* ---------- Category divider ---------- */

        .divider-arch {
            position: absolute;
            inset: 0.4in 0.35in 1.1in;
            overflow: hidden;
            border-radius: 3.65in 3.65in 0 0;
            background: #cdb79c;
        }

        .divider-arch img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .divider-line {
            position: absolute;
            inset: 0.62in 0.57in 1.32in;
            z-index: 2;
            border: 1.5px solid rgba(110, 66, 48, 0.85);
            border-radius: 3.43in 3.43in 0 0;
            pointer-events: none;
        }

        /* Brown tab in the photo's bottom-left corner, like the price tags of the wholesale catalog. */
        .divider-name {
            position: absolute;
            left: 0.35in;
            bottom: 1.1in;
            z-index: 3;
            max-width: 6.2in;
            padding: 0.16in 0.5in 0.14in 0.32in;
            border-radius: 0 0.5in 0 0;
            background: rgba(110, 63, 44, 0.9);
            color: #fff;
            font-family: "Inter", Arial, sans-serif;
            font-size: 0.55in;
            font-weight: 800;
            line-height: 1.05;
            letter-spacing: -0.01em;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .divider-note {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0.48in;
            color: var(--brown-soft);
            text-align: center;
            font-size: 0.21in;
            font-style: italic;
        }

        .divider-note strong {
            color: var(--brown);
            font-style: italic;
        }

        /* ---------- Product grid ---------- */

        .grid-page {
            padding: 0.55in 0.55in 0.4in;
        }

        .grid-title {
            margin: 0 0 0.12in;
            color: #6e3f2c;
            text-align: center;
            font-size: 0.5in;
            line-height: 1;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.2in 0.28in;
        }

        .product-card {
            text-align: center;
        }

        .product-arch {
            position: relative;
            height: 2in;
            padding: 0.06in;
            border: 1.5px solid var(--line);
            border-radius: 1.1in 1.1in 0 0;
            background: rgba(255, 255, 255, 0.25);
        }

        .product-arch-inner {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
            border-radius: 1.04in 1.04in 0 0;
            background: #d8c6ae;
        }

        .product-arch-inner img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-arch-empty {
            display: grid;
            place-items: center;
            height: 100%;
            color: #8d7563;
            font-family: "Cormorant Garamond", serif;
            font-size: 0.22in;
        }

        .product-price {
            position: absolute;
            left: 0;
            bottom: 0;
            padding: 0.05in 0.2in 0.04in 0.1in;
            border-radius: 0 0.3in 0 0;
            background: rgba(123, 74, 54, 0.88);
            color: #fff;
            font-size: 0.24in;
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        .product-name {
            margin: 0.08in 0 0;
            font-family: "Cormorant Garamond", serif;
            font-size: 0.2in;
            font-weight: 600;
            line-height: 1.1;
        }

        .product-meta {
            margin: 0.02in 0 0;
            color: var(--brown-soft);
            font-size: 0.11in;
            letter-spacing: 0.04em;
        }

        .grid-note {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0.32in;
            color: var(--brown-soft);
            text-align: center;
            font-size: 0.16in;
            font-style: italic;
        }

        /* ---------- Contact ---------- */

        .contact {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1in;
            text-align: center;
        }

        .contact img {
            width: 2.6in;
            margin-bottom: 0.5in;
        }

        .contact-items {
            display: grid;
            gap: 0.3in;
        }

        .contact-items span {
            display: block;
            margin-bottom: 0.05in;
            color: var(--brown-soft);
            font-size: 0.13in;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .contact-items strong {
            font-family: "Cormorant Garamond", serif;
            font-size: 0.36in;
            font-weight: 600;
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
                zoom: 0.46;
            }
        }
    </style>
</head>

<body>
    <div class="catalog-toolbar">
        <div>
            <strong>Catálogo minorista · <?= e($dateLong) ?></strong>
            <small>En Destino elige "Guardar como PDF" (no "Microsoft Print to PDF") para que el archivo se llame "<?= e($fileTitle) ?>.pdf".</small>
        </div>
        <div class="catalog-toolbar-actions">
            <a href="<?= e(APP_BASE_URL) ?>/admin/products/">← Productos</a>
            <button type="button" onclick="window.print()">Guardar PDF / Imprimir</button>
        </div>
    </div>

    <?php if ($sections === []): ?>
        <div class="empty-state">
            No hay productos activos en categorías visibles. Activa productos para armar el catálogo.
        </div>
    <?php else: ?>
        <div class="catalog-pages">

            <?php if ($hasFixedCover): ?>
            <section class="page cover-exact">
                <img src="<?= e(catalog_image_url($fixedCover)) ?>?v=<?= (int) @filemtime(__DIR__ . '/../../' . $fixedCover) ?>" alt="Catálogo <?= e(business_name()) ?>">
            </section>
            <?php else: ?>
            <section class="page <?= $coverImage === '' ? 'cover-plain' : '' ?>">
                <div class="cover-frame">
                    <?php if ($coverImage !== ''): ?>
                        <img src="<?= e(catalog_image_url($coverImage)) ?>" alt="">
                    <?php endif; ?>
                </div>
                <div class="cover-seal">
                    <div><strong><?= e(business_name()) ?></strong><?= e(setting('business_tagline')) ?></div>
                </div>
                <div class="cover-logo <?= $coverImage === '' ? 'cover-logo-dark' : '' ?>">
                    <img src="<?= e($logo) ?>" alt="<?= e(business_name()) ?>">
                </div>
                <div class="cover-title">
                    <span class="script">Catálogo</span>
                    <span class="cover-kind">Minorista</span>
                    <span class="cover-date"><?= e($dateLong) ?></span>
                </div>
            </section>
            <?php endif; ?>

            <section class="page about">
                <h2 class="page-heading"><span class="script">Sobre</span>Nosotros</h2>
                <?php foreach ($aboutParagraphs as $paragraph): ?>
                    <p><?= nl2br(e($paragraph)) ?></p>
                <?php endforeach; ?>
                <ul class="about-list">
                    <?php if (CATALOG_WHATSAPP !== ''): ?>
                        <li><b>Pedidos:</b> por WhatsApp al <?= e(CATALOG_WHATSAPP) ?></li>
                    <?php endif; ?>
                    <li><b>Precios:</b> por par, vigentes al <?= e($dateLong) ?></li>
                    <li><b>Envíos</b> a toda Nicaragua</li>
                </ul>
            </section>

            <?php foreach ($sections as $section): ?>
                <section class="page">
                    <div class="divider-arch">
                        <?php if ($section['cover'] !== ''): ?>
                            <img src="<?= e(catalog_image_url($section['cover'])) ?>" alt="">
                        <?php endif; ?>
                    </div>
                    <div class="divider-line"></div>
                    <div class="divider-name"><?= e($section['name']) ?></div>
                    <?php if ($section['from'] !== null): ?>
                        <div class="divider-note">Desde <strong><?= e(catalog_price($section['from'])) ?></strong> el par</div>
                    <?php endif; ?>
                </section>

                <?php foreach ($section['pages'] as $pageItems): ?>
                    <section class="page grid-page">
                        <h2 class="grid-title script"><?= e($section['name']) ?></h2>
                        <div class="product-grid">
                            <?php foreach ($pageItems as $product): ?>
                                <?php $sizes = catalog_sizes($sizesByProduct[(int) $product['id']] ?? []); ?>
                                <article class="product-card">
                                    <div class="product-arch">
                                        <div class="product-arch-inner">
                                            <?php if (catalog_image_exists($product['main_image'])): ?>
                                                <img src="<?= e(catalog_image_url((string) $product['main_image'])) ?>" alt="<?= e($product['name']) ?>">
                                            <?php else: ?>
                                                <div class="product-arch-empty"><?= e(business_name()) ?></div>
                                            <?php endif; ?>
                                            <div class="product-price"><?= e(catalog_price($product['price'])) ?></div>
                                        </div>
                                    </div>
                                    <p class="product-name"><?= e($product['name']) ?></p>
                                    <p class="product-meta">
                                        <?= e(trim((string) $product['code'])) ?><?= $sizes !== '' ? ' · ' . e($sizes) : '' ?>
                                    </p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <p class="grid-note">Consulta colores y tallas disponibles<?= CATALOG_WHATSAPP !== '' ? ' por WhatsApp ' . e(CATALOG_WHATSAPP) : '' ?></p>
                    </section>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <section class="page contact">
                <img src="<?= e($logo) ?>" alt="<?= e(business_name()) ?>">
                <div class="contact-items">
                    <?php if (CATALOG_WHATSAPP !== ''): ?>
                        <div><span>WhatsApp</span><strong><?= e(CATALOG_WHATSAPP) ?></strong></div>
                    <?php endif; ?>
                    <?php if (CATALOG_INSTAGRAM !== ''): ?>
                        <div><span>Instagram</span><strong><?= e(CATALOG_INSTAGRAM) ?></strong></div>
                    <?php endif; ?>
                    <?php if (social_handle('tiktok_user') !== ''): ?>
                        <div><span>TikTok</span><strong>@<?= e(social_handle('tiktok_user')) ?></strong></div>
                    <?php endif; ?>
                    <?php if (setting('facebook_url') !== ''): ?>
                        <div><span>Facebook</span><strong><?= e(preg_replace('#^https?://(www\.)?facebook\.com/#i', '', setting('facebook_url'))) ?></strong></div>
                    <?php endif; ?>
                </div>
            </section>

        </div>
    <?php endif; ?>

    <?php if ($autoPrint && $sections !== []): ?>
        <script>
            window.addEventListener('load', function () {
                window.print();
            });
        </script>
    <?php endif; ?>
</body>

</html>
