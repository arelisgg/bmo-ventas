<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wholesale-functions.php';
require_once __DIR__ . '/includes/sales-functions.php';
require_once __DIR__ . '/includes/promotion-functions.php';

// Active promotions with an image (Administración > Promociones).
$catalogPromotions = catalog_promotions($pdo);
/*
|--------------------------------------------------------------------------
| Load Categories
|--------------------------------------------------------------------------
*/
$categoryStatement = $pdo->query(
  'SELECT
        id,
        name,
        slug,
        image,
        is_coming_soon
     FROM categories
     WHERE is_active = 1
     ORDER BY
        sort_order ASC,
        name ASC'
);
$categories = $categoryStatement->fetchAll();
/*
|--------------------------------------------------------------------------
| Load Active Products
|--------------------------------------------------------------------------
*/
// Products with an order (Administración > Productos, column Orden) go
// first; the ones without order (0) follow in random order on every visit.
$productStatement = $pdo->query(
  'SELECT
        p.id,
        p.category_id,
        p.code,
        p.name,
        p.slug,
        p.description,
        p.price,
        p.main_image,
        p.is_featured,
        p.is_new,
        ' . wholesale_select_columns($pdo) . '
        p.sort_order,        c.name AS category_name,
        c.slug AS category_slug     FROM products p     INNER JOIN categories c
        ON c.id = p.category_id     WHERE
        p.is_active = 1
        AND c.is_active = 1
        AND c.is_coming_soon = 0     ORDER BY
        p.sort_order = 0 ASC,
        p.sort_order ASC,
        RAND()'
);
$products = $productStatement->fetchAll();
$hasWholesaleProducts = count(array_filter(
  $products,
  static fn (array $product): bool => wholesale_label($product) !== ''
)) > 0;
/*
|--------------------------------------------------------------------------
| Load Product Variants
|--------------------------------------------------------------------------
*/
$variantsByProduct = [];
if (count($products) > 0) {
  $productIds = array_column(
    $products,
    'id'
  );
  $placeholders = implode(
    ',',
    array_fill(
      0,
      count($productIds),
      '?'
    )
  );
  $variantStatement = $pdo->prepare(
    "SELECT
            product_id,
            color_name,
            color_hex,
            size,
            stock
         FROM product_variants
         WHERE product_id IN ($placeholders)
         ORDER BY
            product_id ASC,
            color_name ASC,
            CAST(size AS UNSIGNED) ASC"
  );
  $variantStatement->execute(
    $productIds
  );
  $variants = $variantStatement->fetchAll();
  foreach (
    $variants
    as
    $variant
  ) {
    $productId =
      (int) 
      $variant['product_id'];
    $colorName =
      trim(
        (string) 
        $variant['color_name']
      );
    $colorKey =
      strtolower(
        $colorName
      );
    if (
      !isset(
      $variantsByProduct[
        $productId
      ][
        $colorKey
      ]
    )
    ) {
      $variantsByProduct[
        $productId
      ][
        $colorKey
      ] = [
        'name' =>
          $colorName,
        'hex' =>
          $variant['color_hex']
          ?: '#d8c6b5',
        'sizes' =>
          [],
      ];
    }
    $variantsByProduct[
      $productId
    ][
      $colorKey
    ][
      'sizes'
    ][] = [
      'size' =>
        (string) 
        $variant['size'],
      'stock' =>
        (int) 
        $variant['stock'],
    ];
  }
}
/*
|--------------------------------------------------------------------------
| Load Product Gallery Images
|--------------------------------------------------------------------------
*/
$galleryByProduct = [];

if (count($products) > 0) {
  $galleryProductIds = array_column(
    $products,
    'id'
  );

  $galleryPlaceholders = implode(
    ',',
    array_fill(
      0,
      count($galleryProductIds),
      '?'
    )
  );

  $galleryStatement = $pdo->prepare(
    "SELECT
        product_id,
        image_path,
        sort_order
     FROM product_images
     WHERE product_id IN ($galleryPlaceholders)
     ORDER BY
        product_id ASC,
        sort_order ASC,
        id ASC"
  );

  $galleryStatement->execute(
    $galleryProductIds
  );

  foreach (
    $galleryStatement->fetchAll()
    as
    $galleryImage
  ) {
    $galleryByProduct[
      (int) $galleryImage['product_id']
    ][] = $galleryImage['image_path'];
  }
}

/*
|--------------------------------------------------------------------------
| Build Product Data
|--------------------------------------------------------------------------
*/
foreach (
  $products
  as
  &$product
) {
  $productId =
    (int) 
    $product['id'];
  $productColors =
    array_values(
      $variantsByProduct[
        $productId
      ]
      ?? []
    );
  $availableSizes = [];
  $availableColors = [];
  $totalStock = 0;
  foreach (
    $productColors
    as
    &$color
  ) {
    $colorStock = 0;
    foreach (
      $color['sizes']
      as
      $sizeItem
    ) {
      $stock =
        (int) 
        $sizeItem['stock'];
      $totalStock += $stock;
      $colorStock += $stock;
      if ($stock > 0) {
        $availableSizes[
          $sizeItem['size']
        ] = true;
      }
    }
    if ($colorStock > 0) {
      $availableColors[
        strtolower(
          $color['name']
        )
      ] = true;
    }
    $color['total_stock'] =
      $colorStock;
  }
  unset($color);
  $product['colors'] =
    $productColors;
  $product['available_sizes'] =
    array_map(
      'strval',
      array_keys(
        $availableSizes
      )
    );

  sort(
    $product['available_sizes'],
    SORT_NUMERIC
  );
  $product['available_colors'] =
    array_keys(
      $availableColors
    );
  $product['total_stock'] =
    $totalStock;

  $product['gallery_images'] =
    $galleryByProduct[
      (int) $product['id']
    ]
    ?? [];
}
unset($product);
/*
|--------------------------------------------------------------------------
| Filter Options
|--------------------------------------------------------------------------
*/
$catalogColors = [];
foreach (
  $products
  as
  $product
) {
  foreach (
    $product['colors']
    as
    $color
  ) {
    if (
      (int) 
      $color['total_stock']
      <= 0
    ) {
      continue;
    }
    $colorKey =
      strtolower(
        $color['name']
      );
    $catalogColors[
      $colorKey
    ] = $color['name'];
  }
}
asort(
  $catalogColors,
  SORT_NATURAL |
  SORT_FLAG_CASE
);
/*
|--------------------------------------------------------------------------
| Coming Soon Data
|--------------------------------------------------------------------------
*/
$comingSoonData = [];
foreach (
  $categories
  as
  $category
) {
  if (
    (int) 
    $category['is_coming_soon']
    !== 1
  ) {
    continue;
  }
  $comingSoonData[
    $category['slug']
  ] = [
    'title' =>
      $category['name'],
    'image' =>
      $category['image'],
    'text' =>
      'Muy pronto descubrirás nuevos productos de ' . business_name() . '.',
  ];
}
$socialLinks = array_filter([
  'Facebook' => setting('facebook_url'),
  'Instagram' => instagram_url(),
  'TikTok' => tiktok_url(),
]); ?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Catálogo <?= e(business_name()) ?>.">
  <title>
    <?= e(business_name()) ?> | Catálogo
  </title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600&display=swap"
    rel="stylesheet">
  <link href="styles.css" rel="stylesheet">
  <link href="<?= e(business_favicon_url()) ?>" rel="icon">
</head>

<body>
  <header class="site-header">
    <div class="container header-inner"> <a class="brand" href="#top" aria-label="<?= e(business_name()) ?> inicio"> <img
          class="brand-logo" src="<?= e(business_logo_url()) ?>" alt="<?= e(business_name()) ?>"> </a>
      <nav class="main-nav" aria-label="Navegación principal"> <?php if ($catalogPromotions !== []): ?><a href="#promotions">
          Promociones
        </a> <?php endif; ?><a href="#categories">
          Categorías
        </a> <a href="#favorites">
          Favoritos
        </a> <a href="#contact">
          Contacto
        </a> </nav>
    </div>
  </header>
  <main id="top">
    <section class="hero">
      <div class="hero-image-wrap"> <?php if (setting('hero_image_path') !== ''): ?><img class="hero-image" src="<?= e(setting_image_url('hero_image_path')) ?>" alt="Colección <?= e(business_name()) ?>"><?php endif; ?>
        <div class="hero-overlay"></div>
        <div class="container hero-content">
          <div class="hero-copy"> <span class="eyebrow">
              CATÁLOGO
            </span>
            <h1>
              Colección
            </h1> <img class="hero-logo" src="<?= e(business_logo_url()) ?>" alt="<?= e(business_name()) ?>">
            <div class="decorative-line"></div>
            <p>
              <?= e(setting('hero_text')) ?>
            </p> <a class="button button-dark" href="#categories">
              Explorar colección
            </a>
          </div>
        </div>
      </div>
    </section>
    <?php if ($catalogPromotions !== []): ?>
      <section class="section promotions-section" id="promotions">
        <div class="container">
          <div class="section-heading"> <span class="eyebrow">
              PROMOCIONES
            </span>
            <h2>
              Ofertas del momento
            </h2>
            <div class="decorative-line centered"></div>
          </div>
          <div class="promotion-grid">
            <?php foreach ($catalogPromotions as $promotion): ?>
              <article class="promotion-card">
                <div class="promotion-card-media">
                  <img class="promotion-card-image" src="<?= e(APP_BASE_URL . '/' . ltrim((string) $promotion['image_path'], '/')) ?>"
                    alt="<?= e($promotion['name']) ?>" loading="lazy">
                </div>
                <div class="promotion-card-body">
                  <span class="eyebrow">PROMOCIÓN</span>
                  <h3><?= e($promotion['name']) ?></h3>
                  <p><?= e(ucfirst(promotion_summary($promotion))) ?><?php if ($promotion['ends_on'] !== null): ?> · hasta el <?= e(date('d/m/Y', strtotime((string) $promotion['ends_on']))) ?><?php endif; ?></p>
                  <?php if (whatsapp_digits() !== ''): ?>
                    <a class="button button-dark" href="<?= e(whatsapp_link('Hola, me interesa la promoción "' . $promotion['name'] . '".')) ?>"
                      target="_blank" rel="noopener noreferrer">
                      Pedir por WhatsApp
                    </a>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
    <section class="section" id="categories">
      <div class="container">
        <div class="section-heading">
          <h2>
            Explora por categoría
          </h2>
          <div class="
                        decorative-line
                        centered
                    "></div>
        </div>
        <div class="category-grid">
          <a class="category-card category-filter category-card-all" href="#favorites" data-filter="all">
            <div class="category-media category-media-all">

              <div class="category-all-content">

                <span>
                  CATÁLOGO
                </span>

                <strong>
                  Ver todos
                </strong>

              </div>

            </div>

            <div class="category-card-footer">

              <h3>
                TODOS
              </h3>

              <span>
                →
              </span>

            </div>

          </a>
          <?php if ($hasWholesaleProducts): ?>
            <a class="category-card category-filter category-card-all category-card-wholesale" href="#favorites" data-filter="__wholesale">
              <div class="category-media category-media-all">
                <div class="category-all-content">
                  <span>
                    POR MAYOR
                  </span>
                  <strong>
                    Precios mayoristas
                  </strong>
                </div>
              </div>
              <div class="category-card-footer">
                <h3>
                  MAYORISTA
                </h3>
                <span>
                  →
                </span>
              </div>
            </a>
          <?php endif; ?>
          <?php foreach (
            $categories
            as
            $category
          ): ?>
            <a class="
                            category-card
                            category-filter
                            <?= (
                              (int) 
                              $category[
                                'is_coming_soon'
                              ]
                              === 1
                            )
                              ? 'category-card-coming'
                              : ''
                              ?>
                        " href="#favorites" data-filter="<?= e(
                          $category['slug']
                        ) ?>">
              <div class="arched-media"> <img src="<?= e(
                $category['image']
              ) ?>" alt="<?= e(
                 $category['name']
               ) ?> <?= e(business_name()) ?>">
                <?php if (
                  (int) 
                  $category[
                    'is_coming_soon'
                  ]
                  === 1
                ): ?>
                  <div class="coming-overlay"></div> <span class="coming-badge">
                    PRÓXIMAMENTE
                  </span> <?php endif; ?>
              </div>
              <h3> <?= e(
                strtoupper(
                  $category['name']
                )
              ) ?> </h3> <span class="arrow">
                →
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <section class="section products-section" id="favorites">
      <div class="container">

        <div class="section-heading"> <span class="eyebrow">
            COLECCIÓN ACTUAL
          </span>
          <h2>
            Nuevos favoritos
          </h2>
        </div> <!-- COMING SOON -->
        <div class="coming-soon-grid-message" id="comingSoonGrid" hidden>
          <div class="coming-soon-grid-image"> <img id="comingSoonGridImage" src="" alt=""> </div>
          <div class="coming-soon-grid-copy"> <span class="eyebrow">
              PRÓXIMAMENTE
            </span>
            <h3 id="comingSoonGridTitle"></h3>
            <p id="comingSoonGridText"></p> <button class="
                            button
                            button-outline
                            show-all-products
                        " type="button">
              Volver al catálogo
            </button>
          </div>
        </div>
        <!-- DYNAMIC PRODUCTS -->
        <div class="product-grid">
          <?php foreach (
            $products
            as
            $product
          ): ?>
            <?php $availableSizes =
              $product[
                'available_sizes'
              ];
            $availableColors =
              $product[
                'available_colors'
              ];
            $displayColors = [];
            foreach (
              $product['colors']
              as
              $color
            ) {
              if (
                (int) 
                $color[
                  'total_stock'
                ]
                > 0
              ) {
                $displayColors[] =
                  $color;
              }
            }
            $galleryImages =
              array_values(
                array_unique(
                  array_filter(
                    array_merge(
                      [
                        $product['main_image'],
                      ],
                      $product['gallery_images']
                      ?? []
                    )
                  )
                )
              );

            $productData = [
              'id' =>
                (int) $product['id'],

              'code' =>
                $product['code'],

              'name' =>
                $product['name'],

              'description' =>
                $product['description']
                ?? '',

              'image' =>
                $product['main_image'],

              'gallery' =>
                $galleryImages,

              'price' =>
                $product['price']
                !== null
                ? currency_symbol() .
                number_format(
                  (float) $product['price'],
                  2
                )
                : 'Consultar precio',

              'total_stock' =>
                (int) $product['total_stock'],

              'wholesale' =>
                wholesale_label($product),

              'wholesale_price' =>
                wholesale_label($product) !== ''
                ? (float) $product['wholesale_price']
                : null,

              'wholesale_min' =>
                (int) ($product['wholesale_min_qty'] ?? 0),

              'colors' =>
                $product['colors'],
            ]; ?>
            <article class="product-card" data-wholesale="<?= wholesale_label($product) !== '' ? '1' : '0' ?>" data-category="<?= e(
              $product[
                'category_slug'
              ]
            ) ?>" data-sizes="<?= e(
               implode(
                 ',',
                 $availableSizes
               )
             ) ?>" data-colors="<?= e(
                implode(
                  '|',
                  $availableColors
                )
              ) ?>">
              <a class="
                                product-media
                                open-product-detail
                            " href="#productDetail" aria-label="Ver detalle de <?= e(
                              $product['name']
                            ) ?>"> <img src="<?= e(
                               $product[
                                 'main_image'
                               ]
                             ) ?>" alt="<?= e(
                                $product[
                                  'name'
                                ]
                              ) ?>"> </a>
              <div class="product-info">
                <p class="product-code"> <?= e(
                  $product['code']
                ) ?> </p>
                <h3> <?= e(
                  $product['name']
                ) ?> </h3>
                <p class="product-price"> <?php if (
                  $product['price']
                  !== null
                ): ?> <?= e(currency_symbol()) ?><?= number_format(
                    (float) 
                    $product[
                      'price'
                    ],
                    2
                  ) ?>   <?php else: ?> Consultar precio <?php endif; ?> </p>
                <?php if (wholesale_label($product) !== ''): ?>
                  <p class="product-wholesale-price"><?= e(wholesale_label($product)) ?></p>
                <?php endif; ?>
                <div class="product-options">
                  <div class="product-option-row"> <span class="product-option-label">
                      Color
                    </span>
                    <div class="product-color-list">
                      <?php if (
                        count(
                          $displayColors
                        )
                        > 0
                      ): ?>
                        <?php foreach (
                          $displayColors
                          as
                          $color
                        ): ?>
                          <span class="color-option"> <span class="color-swatch" style="--swatch: <?= e(
                            $color[
                              'hex'
                            ]
                          ) ?>;"></span> <?= e(
                             $color[
                               'name'
                             ]
                           ) ?> </span>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <span class="out-of-stock">
                          Sin stock
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="product-option-row"> <span class="product-option-label">
                      Talla
                    </span>
                    <div class="size-list">
                      <?php if (
                        count(
                          $availableSizes
                        )
                        > 0
                      ): ?>
                        <?php foreach (
                          $availableSizes
                          as
                          $size
                        ): ?>
                          <span class="size-chip"> <?= e(
                            $size
                          ) ?> </span>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <span class="out-of-stock">
                          Agotado
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div> <button class="
                                    product-detail-link
                                    open-product-detail
                                " type="button">
                  VER DETALLE
                </button>
                <script type="application/json" class="product-json"><?= json_encode(
                  $productData,
                  JSON_UNESCAPED_UNICODE |
                  JSON_UNESCAPED_SLASHES |
                  JSON_HEX_TAG |
                  JSON_HEX_AMP |
                  JSON_HEX_APOS |
                  JSON_HEX_QUOT
                ) ?></script>
              </div>
            </article>
          <?php endforeach; ?>
          <?php if (
            count($products)
            === 0
          ): ?>
            <div class="catalog-empty">
              <h3>
                No hay productos disponibles.
              </h3>
              <p>
                Muy pronto tendremos nuevos
                productos.
              </p>
            </div> <?php endif; ?>
        </div>
      </div>
    </section>
    <section class="benefits">
      <div class="container benefits-grid">
        <article class="benefit">
          <div class="benefit-icon">
            ✦
          </div>
          <div>
            <h3>
              DISEÑOS EXCLUSIVOS
            </h3>
            <p>
              Estilo único, creado para ti.
            </p>
          </div>
        </article>
        <article class="benefit">
          <div class="benefit-icon">
            ✓
          </div>
          <div>
            <h3>
              CALIDAD QUE SE SIENTE
            </h3>
            <p>
              Materiales seleccionados
              con detalle.
            </p>
          </div>
        </article>
        <article class="benefit">
          <div class="benefit-icon">
            ♡
          </div>
          <div>
            <h3>
              COMPRA SEGURA
            </h3>
            <p>
              Compra directamente
              con <?= e(business_name()) ?>.
            </p>
          </div>
        </article>
      </div>
    </section>
  </main>
  <footer class="site-footer" id="contact">
    <div class="container footer-main">

      <!-- Brand -->
      <div class="footer-brand-column">
        <a class="footer-brand" href="#top" aria-label="<?= e(business_name()) ?> inicio">
          <img class="footer-logo" src="<?= e(business_footer_logo_url()) ?>" alt="<?= e(business_name()) ?>">
        </a>
      </div>
      <!-- Contact -->
      <div class="footer-column">
        <a class="footer-contact-link" href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener noreferrer">
          WhatsApp
          <strong>
            <?= e(setting('whatsapp_number')) ?>
          </strong>
        </a>
        <p class="footer-note">
          Consulta disponibilidad,
          tallas y colores.
        </p>
      </div>
      <!-- Social -->
      <div class="footer-column footer-social-column">
        <span class="footer-heading">
          Síguenos
        </span>
        <div class="footer-social-links">
          <?php foreach ($socialLinks as $socialName => $socialUrl): ?>
            <a href="<?= e($socialUrl) ?>" target="_blank" rel="noopener noreferrer"
              aria-label="<?= e($socialName) ?>">
              <span>
                <?= e($socialName) ?>
              </span>
              <span class="footer-social-arrow">
                ↗
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="container">
      <div class="footer-bottom">
        <p>
          ©
          <?= date('Y') ?> <?= e(business_name()) ?>.
          Todos los derechos reservados.
        </p>
        <a href="#top">
          Volver arriba ↑
        </a>
      </div>
    </div>
  </footer>


  <div class="product-modal" id="productDetail" hidden>
    <div class="product-modal-backdrop" data-close-modal></div>
    <div class="product-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="modalProductName">
      <button class="product-modal-close" type="button" data-close-modal aria-label="Cerrar">
        ×
      </button>
      <div class="product-modal-gallery">
        <div class="modal-thumbnails" id="modalThumbnails"></div>
        <div class="modal-main-image"> <img id="modalProductImage" src="" alt=""> </div>
      </div>
      <div class="product-modal-info">
        <p class="modal-product-code" id="modalProductCode"></p>
        <h2 id="modalProductName">
          Producto
        </h2>
        <p class="modal-product-price" id="modalProductPrice">
          Consultar precio
        </p>
        <p class="modal-product-wholesale" id="modalProductWholesale" hidden></p>
        <div class="modal-purchase-mode" id="modalPurchaseMode" hidden>
          <button type="button" class="is-selected" data-purchase-mode="retail">AL DETALLE</button>
          <button type="button" data-purchase-mode="wholesale">AL POR MAYOR</button>
        </div>
        <div class="modal-option"> <span class="modal-option-title"> Color: <strong id="modalColorName"></strong>
          </span>
          <div class="modal-color-options" id="modalColorOptions"></div>
        </div>
        <div class="modal-option"> <span class="modal-option-title"> Talla: <strong id="modalSizeName">
              Selecciona
            </strong> </span>
          <div class="modal-size-options" id="modalSizeOptions"></div>
        </div> <button class="modal-buy-button" id="modalBuyButton" type="button" disabled>
          SELECCIONA UNA TALLA
        </button>
        <div class="modal-wholesale-order" id="modalWholesaleOrder" hidden>
          <div class="modal-wholesale-panel" id="modalWholesalePanel" hidden>
            <span class="modal-option-title">
              Pedido mayorista: <strong>cantidad por talla</strong>
            </span>
            <div class="modal-wholesale-sizes" id="modalWholesaleSizes"></div>
            <p class="modal-wholesale-summary" id="modalWholesaleSummary"></p>
            <button class="modal-buy-button modal-wholesale-button" id="modalWholesaleButton" type="button" disabled>
              PEDIR AL POR MAYOR
            </button>
          </div>
        </div>
        <a class="modal-whatsapp-help" href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener noreferrer"> <span
            class="whatsapp-mark">
            ◉
          </span> ¿Necesitas ayuda con tu talla?
          <br> Escríbenos por WhatsApp </a>
      </div>
    </div>
  </div>
  <script>

    const BUSINESS_NAME = <?= json_encode(business_name(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    const WHATSAPP_NUMBER = <?= json_encode(whatsapp_digits()) ?>;
    const CURRENCY_SYMBOL = <?= json_encode(currency_symbol(), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;

    document.addEventListener(
      "DOMContentLoaded",
      () => {

        /*
        |--------------------------------------------------------------------------
        | Catalog Elements
        |--------------------------------------------------------------------------
        */

        const cards =
          Array.from(
            document.querySelectorAll(
              ".product-card"
            )
          );

        const grid =
          document.querySelector(
            ".product-grid"
          );

        const categoryCards =
          Array.from(
            document.querySelectorAll(
              ".category-filter"
            )
          );


        /*
        |--------------------------------------------------------------------------
        | Coming Soon Elements
        |--------------------------------------------------------------------------
        */

        const comingPanel =
          document.getElementById(
            "comingSoonGrid"
          );

        const comingImage =
          document.getElementById(
            "comingSoonGridImage"
          );

        const comingTitle =
          document.getElementById(
            "comingSoonGridTitle"
          );

        const comingText =
          document.getElementById(
            "comingSoonGridText"
          );


        const comingSoonContent =
          <?= json_encode(
            $comingSoonData,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_HEX_TAG |
            JSON_HEX_AMP |
            JSON_HEX_APOS |
            JSON_HEX_QUOT
          ) ?>;


        /*
        |--------------------------------------------------------------------------
        | Modal Elements
        |--------------------------------------------------------------------------
        */

        const modal =
          document.getElementById(
            "productDetail"
          );

        const modalImage =
          document.getElementById(
            "modalProductImage"
          );

        const modalThumbnails =
          document.getElementById(
            "modalThumbnails"
          );

        const modalCode =
          document.getElementById(
            "modalProductCode"
          );

        const modalName =
          document.getElementById(
            "modalProductName"
          );

        const modalPrice =
          document.getElementById(
            "modalProductPrice"
          );

        const modalColorName =
          document.getElementById(
            "modalColorName"
          );

        const modalColorOptions =
          document.getElementById(
            "modalColorOptions"
          );

        const modalSizeName =
          document.getElementById(
            "modalSizeName"
          );

        const modalSizeOptions =
          document.getElementById(
            "modalSizeOptions"
          );

        const modalBuyButton =
          document.getElementById(
            "modalBuyButton"
          );


        let currentProduct = null;


        /*
        |--------------------------------------------------------------------------
        | Hide Coming Soon
        |--------------------------------------------------------------------------
        */

        function hideComingSoon() {

          if (comingPanel) {

            comingPanel.hidden =
              true;

          }


          if (grid) {

            grid.classList.remove(
              "is-catalog-hidden"
            );

          }

        }


        /*
        |--------------------------------------------------------------------------
        | Show Coming Soon
        |--------------------------------------------------------------------------
        */

        function showComingSoon(
          category
        ) {

          const content =
            comingSoonContent[
            category
            ];


          if (!content) {

            return false;

          }


          /*
          |--------------------------------------------------------------------------
          | Hide Product Catalog
          |--------------------------------------------------------------------------
          */

          if (grid) {

            grid.classList.add(
              "is-catalog-hidden"
            );

          }


          /*
          |--------------------------------------------------------------------------
          | Show Coming Soon Panel
          |--------------------------------------------------------------------------
          */

          if (comingPanel) {

            comingPanel.hidden =
              false;

          }


          if (comingImage) {

            comingImage.src =
              content.image;

            comingImage.alt =
              content.title;

          }


          if (comingTitle) {

            comingTitle.textContent =
              content.title;

          }


          if (comingText) {

            comingText.textContent =
              content.text;

          }


          return true;

        }


        /*
        |--------------------------------------------------------------------------
        | Active Category
        |--------------------------------------------------------------------------
        */

        function setActiveCategory(
          category
        ) {

          categoryCards.forEach(
            card => {

              const isActive =
                card.dataset.filter ===
                category;


              card.classList.toggle(
                "is-selected",
                isActive
              );

            }
          );

        }


        /*
        |--------------------------------------------------------------------------
        | Filter By Category
        |--------------------------------------------------------------------------
        */

        function filterByCategory(
          category
        ) {

          /*
          |--------------------------------------------------------------------------
          | Coming Soon Categories
          |--------------------------------------------------------------------------
          */

          if (
            showComingSoon(
              category
            )
          ) {

            setActiveCategory(
              category
            );

            return;

          }


          /*
          |--------------------------------------------------------------------------
          | Normal Categories
          |--------------------------------------------------------------------------
          */

          hideComingSoon();


          cards.forEach(
            card => {

              const cardCategory =
                card.dataset.category;


              const visible =
                category === "all" ||
                (
                  category === "__wholesale"
                    ? card.dataset.wholesale === "1"
                    : cardCategory === category
                );


              card.classList.toggle(
                "is-hidden",
                !visible
              );

            }
          );


          setActiveCategory(
            category
          );

        }


        /*
        |--------------------------------------------------------------------------
        | Category Cards
        |--------------------------------------------------------------------------
        */

        categoryCards.forEach(
          categoryCard => {

            categoryCard.addEventListener(
              "click",
              event => {

                event.preventDefault();


                const category =
                  categoryCard
                    .dataset
                    .filter;


                if (!category) {

                  return;

                }


                filterByCategory(
                  category
                );


                const catalogSection =
                  document.getElementById(
                    "favorites"
                  );


                if (catalogSection) {

                  catalogSection
                    .scrollIntoView({
                      behavior:
                        "smooth",

                      block:
                        "start"
                    });

                }

              }
            );

          }
        );


        /*
        |--------------------------------------------------------------------------
        | Back To Catalog
        |--------------------------------------------------------------------------
        */

        document
          .querySelectorAll(
            ".show-all-products"
          )
          .forEach(
            button => {

              button.addEventListener(
                "click",
                () => {

                  filterByCategory(
                    "all"
                  );

                }
              );

            }
          );


        /*
        |--------------------------------------------------------------------------
        | Get Product Data
        |--------------------------------------------------------------------------
        */

        function getProductData(
          card
        ) {

          const jsonElement =
            card.querySelector(
              ".product-json"
            );


          if (!jsonElement) {

            return null;

          }


          try {

            return JSON.parse(
              jsonElement.textContent
            );

          } catch (error) {

            console.error(
              "Invalid product data.",
              error
            );


            return null;

          }

        }


        /*
        |--------------------------------------------------------------------------
        | Render Sizes
        |--------------------------------------------------------------------------
        */

        /*
        |--------------------------------------------------------------------------
        | Wholesale Order (several sizes in one WhatsApp message)
        |--------------------------------------------------------------------------
        */

        const wholesaleOrder =
          document.getElementById("modalWholesaleOrder");

        const wholesaleSizes =
          document.getElementById("modalWholesaleSizes");

        const wholesaleSummary =
          document.getElementById("modalWholesaleSummary");

        const wholesaleButton =
          document.getElementById("modalWholesaleButton");

        const wholesalePanel =
          document.getElementById("modalWholesalePanel");

        const purchaseMode =
          document.getElementById("modalPurchaseMode");

        // Retail purchase pieces: size picker block and buy button.
        const retailPieces = [
          modalSizeOptions.closest(".modal-option"),
          modalBuyButton
        ];

        function setWholesaleOpen(isOpen) {
          wholesalePanel.hidden = !isOpen;

          retailPieces.forEach(piece => {
            piece.hidden = isOpen;
          });

          purchaseMode
            .querySelectorAll("[data-purchase-mode]")
            .forEach(button => {
              button.classList.toggle(
                "is-selected",
                (button.dataset.purchaseMode === "wholesale") === isOpen
              );
            });
        }

        purchaseMode.addEventListener("click", event => {
          const button = event.target.closest("[data-purchase-mode]");

          if (button) {
            setWholesaleOpen(button.dataset.purchaseMode === "wholesale");
          }
        });

        const formatCordobas = value =>
          CURRENCY_SYMBOL + Number(value).toLocaleString("en-US", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
          });

        function wholesaleLines() {
          return Array.from(
            wholesaleSizes.querySelectorAll("input")
          )
            .map(input => ({
              size: input.dataset.size,
              quantity: Math.max(0, parseInt(input.value, 10) || 0)
            }))
            .filter(line => line.quantity > 0);
        }

        function updateWholesaleSummary() {
          const product = currentProduct;
          const total = wholesaleLines().reduce(
            (sum, line) => sum + line.quantity,
            0
          );
          const minimum = Math.max(1, Number(product.wholesale_min) || 1);

          wholesaleSummary.textContent =
            total === 0
              ? (minimum > 1 ? `Mínimo ${minimum} pares.` : "")
              : `${total} pares · ${formatCordobas(total * product.wholesale_price)}` +
                (total < minimum ? ` · mínimo ${minimum} pares` : "");

          wholesaleButton.disabled = total < minimum;
        }

        function renderWholesaleOrder(color) {
          const product = currentProduct;

          wholesaleSizes.innerHTML = "";
          wholesaleOrder.hidden =
            !product || !product.wholesale_price || !color;

          if (wholesaleOrder.hidden) {
            purchaseMode.hidden = true;
            setWholesaleOpen(false);
            return;
          }

          purchaseMode.hidden = false;

          color.sizes.forEach(item => {
            const label = document.createElement("label");
            const input = document.createElement("input");

            label.className = "modal-wholesale-size";
            label.textContent = item.size;

            input.type = "number";
            input.min = "0";
            input.step = "1";
            input.placeholder = "0";
            input.dataset.size = item.size;
            input.addEventListener("input", updateWholesaleSummary);

            label.appendChild(input);
            wholesaleSizes.appendChild(label);
          });

          updateWholesaleSummary();
        }

        wholesaleButton.addEventListener("click", () => {
          const product = currentProduct;
          const lines = wholesaleLines();
          const total = lines.reduce((sum, line) => sum + line.quantity, 0);

          if (!product || wholesaleButton.disabled) {
            return;
          }

          const message = [
            `Hola ${BUSINESS_NAME}, quiero hacer un pedido mayorista:`,
            "",
            `Producto: ${product.name}`,
            `Código: ${product.code}`,
            `Color: ${modalColorName.textContent}`,
            ...lines.map(line => `Talla ${line.size}: ${line.quantity}`),
            `Total: ${total} pares`,
            `Precio mayorista: ${formatCordobas(product.wholesale_price)} c/u`,
            `Total estimado: ${formatCordobas(total * product.wholesale_price)}`,
            "",
            "¿Está disponible?"
          ].join("\n");

          window.open(
            "https://wa.me/" + WHATSAPP_NUMBER + "?text=" + encodeURIComponent(message),
            "_blank",
            "noopener,noreferrer"
          );
        });


        function renderSizes(
          color
        ) {

          renderWholesaleOrder(
            color
          );

          modalSizeOptions.innerHTML =
            "";


          modalSizeName.textContent =
            "Selecciona";


          modalBuyButton.disabled =
            true;


          modalBuyButton.textContent =
            "SELECCIONA UNA TALLA";


          const availableSizes =
            color.sizes.filter(
              item =>
                Number(
                  item.stock
                ) > 0
            );


          if (
            availableSizes.length
            === 0
          ) {

            modalSizeName.textContent =
              "Agotado";


            modalBuyButton.textContent =
              "SIN STOCK";


            return;

          }


          availableSizes.forEach(
            item => {

              const button =
                document.createElement(
                  "button"
                );


              button.type =
                "button";


              button.className =
                "modal-size-button";


              button.textContent =
                item.size;


              button.addEventListener(
                "click",
                () => {

                  modalSizeOptions
                    .querySelectorAll(
                      ".modal-size-button"
                    )
                    .forEach(
                      sizeButton => {

                        sizeButton
                          .classList
                          .remove(
                            "is-selected"
                          );

                      }
                    );


                  button
                    .classList
                    .add(
                      "is-selected"
                    );


                  modalSizeName.textContent =
                    item.size;


                  modalBuyButton.disabled =
                    false;


                  modalBuyButton.textContent =
                    "COMPRAR";

                }
              );


              modalSizeOptions
                .appendChild(
                  button
                );

            }
          );

        }


        /*
        |--------------------------------------------------------------------------
        | Select Color
        |--------------------------------------------------------------------------
        */

        function selectColor(
          color,
          button
        ) {

          modalColorOptions
            .querySelectorAll(
              ".modal-color-button"
            )
            .forEach(
              colorButton => {

                colorButton
                  .classList
                  .remove(
                    "is-selected"
                  );

              }
            );


          button
            .classList
            .add(
              "is-selected"
            );


          modalColorName.textContent =
            color.name;


          renderSizes(
            color
          );

        }


        /*
        |--------------------------------------------------------------------------
        | Product Gallery
        |--------------------------------------------------------------------------
        */

        function renderProductGallery(
          product
        ) {

          modalThumbnails.innerHTML =
            "";


          const images =
            Array.isArray(
              product.gallery
            ) &&
              product.gallery.length > 0
              ? product.gallery
              : [
                product.image
              ];


          function selectImage(
            imagePath,
            button
          ) {

            modalImage.src =
              imagePath;


            modalImage.alt =
              product.name;


            modalThumbnails
              .querySelectorAll(
                ".modal-thumbnail"
              )
              .forEach(
                thumbnail => {

                  thumbnail
                    .classList
                    .remove(
                      "is-active"
                    );

                }
              );


            button
              .classList
              .add(
                "is-active"
              );

          }


          images.forEach(
            (
              imagePath,
              index
            ) => {

              const button =
                document.createElement(
                  "button"
                );


              button.type =
                "button";


              button.className =
                "modal-thumbnail";


              const image =
                document.createElement(
                  "img"
                );


              image.src =
                imagePath;


              image.alt =
                `${product.name} ${index + 1}`;


              button.appendChild(
                image
              );


              button.addEventListener(
                "click",
                () => {

                  selectImage(
                    imagePath,
                    button
                  );

                }
              );


              modalThumbnails
                .appendChild(
                  button
                );


              if (index === 0) {

                selectImage(
                  imagePath,
                  button
                );

              }

            }
          );

        }


        /*
        |--------------------------------------------------------------------------
        | Open Product Modal
        |--------------------------------------------------------------------------
        */

        function openProductModal(
          card
        ) {

          const product =
            getProductData(
              card
            );


          if (!product) {

            return;

          }


          currentProduct =
            product;


          renderProductGallery(
            product
          );


          modalCode.textContent =
            product.code;


          modalName.textContent =
            product.name;


          modalPrice.textContent =
            product.price;


          const modalWholesale =
            document.getElementById(
              "modalProductWholesale"
            );

          modalWholesale.textContent =
            product.wholesale || "";

          modalWholesale.hidden =
            !product.wholesale;

          setWholesaleOpen(false);


          modalColorOptions.innerHTML =
            "";


          const availableColors =
            product.colors.filter(
              color =>
                Number(
                  color.total_stock
                ) > 0
            );


          if (
            availableColors.length
            === 0
          ) {

            modalColorName.textContent =
              "Sin stock";


            renderWholesaleOrder(
              null
            );


            modalSizeName.textContent =
              "Agotado";


            modalSizeOptions.innerHTML =
              "";


            modalBuyButton.disabled =
              true;


            modalBuyButton.textContent =
              "SIN STOCK";

          } else {


            availableColors.forEach(
              (
                color,
                index
              ) => {

                const button =
                  document.createElement(
                    "button"
                  );


                button.type =
                  "button";


                button.className =
                  "modal-color-button";


                button.setAttribute(
                  "aria-label",
                  color.name
                );


                button.innerHTML =
                  `<span style="--modal-swatch:${color.hex}"></span>`;


                button.addEventListener(
                  "click",
                  () => {

                    selectColor(
                      color,
                      button
                    );

                  }
                );


                modalColorOptions
                  .appendChild(
                    button
                  );


                if (index === 0) {

                  selectColor(
                    color,
                    button
                  );

                }

              }
            );

          }


          modal.hidden =
            false;


          document.body
            .classList
            .add(
              "modal-open"
            );

        }


        /*
        |--------------------------------------------------------------------------
        | Close Product Modal
        |--------------------------------------------------------------------------
        */

        function closeModal() {

          modal.hidden =
            true;


          document.body
            .classList
            .remove(
              "modal-open"
            );


          currentProduct =
            null;

        }


        /*
        |--------------------------------------------------------------------------
        | Product Detail Triggers
        |--------------------------------------------------------------------------
        */

        document.addEventListener(
          "click",
          event => {

            const trigger =
              event.target.closest(
                ".open-product-detail"
              );


            if (trigger) {

              event.preventDefault();


              const card =
                trigger.closest(
                  ".product-card"
                );


              if (card) {

                openProductModal(
                  card
                );

              }


              return;

            }


            if (
              event.target.closest(
                "[data-close-modal]"
              )
            ) {

              closeModal();

            }

          }
        );


        /*
        |--------------------------------------------------------------------------
        | Escape Key
        |--------------------------------------------------------------------------
        */

        document.addEventListener(
          "keydown",
          event => {

            if (
              event.key ===
              "Escape" &&
              !modal.hidden
            ) {

              closeModal();

            }

          }
        );


        /*
        |--------------------------------------------------------------------------
        | WhatsApp Purchase
        |--------------------------------------------------------------------------
        */

        modalBuyButton.addEventListener(
          "click",
          () => {

            if (
              !currentProduct ||
              modalBuyButton.disabled
            ) {

              return;

            }


            const size =
              modalSizeName.textContent;


            const color =
              modalColorName.textContent;


            const message = [
              `Hola ${BUSINESS_NAME}, me interesa este producto:`,
              "",
              `Producto: ${currentProduct.name}`,
              `Código: ${currentProduct.code}`,
              `Color: ${color}`,
              `Talla: ${size}`,
              `Precio: ${currentProduct.price}`,
              currentProduct.wholesale
                ? `Precio ${currentProduct.wholesale.charAt(0).toLowerCase()}${currentProduct.wholesale.slice(1)}`
                : "",
              "",
              "¿Está disponible?"
            ]
              .filter(Boolean)
              .join("\n");


            const whatsappUrl =
              "https://wa.me/" + WHATSAPP_NUMBER + "?text=" +
              encodeURIComponent(
                message
              );


            window.open(
              whatsappUrl,
              "_blank",
              "noopener,noreferrer"
            );

          }
        );


        /*
        |--------------------------------------------------------------------------
        | Initial Catalog
        |--------------------------------------------------------------------------
        */

        filterByCategory(
          "all"
        );

      }
    );

  </script>
</body>

</html>