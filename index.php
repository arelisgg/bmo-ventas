<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wholesale-functions.php';
require_once __DIR__ . '/includes/sales-functions.php';
require_once __DIR__ . '/includes/promotion-functions.php';

// Active promotions with an image (Administración > Promociones).
$catalogPromotions = catalog_promotions($pdo);
// Every current promotion, to tag the models it really applies to.
$currentPromotions = current_promotions($pdo);
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
  <link href="styles.css?v=<?= (int) @filemtime(__DIR__ . '/styles.css') ?>" rel="stylesheet">
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
      <button class="cart-toggle" id="cartToggle" type="button" aria-label="Ver carrito" aria-controls="cartPanel">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2.5 3.5h2.6l2.4 11.2a1.6 1.6 0 0 0 1.6 1.3h8.6a1.6 1.6 0 0 0 1.6-1.2l1.6-6.8H6.1"/></svg>
        <span class="cart-toggle-label">Carrito</span>
        <span class="cart-count" id="cartCount" hidden>0</span>
      </button>
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

              // Cart (carrito): numeric price and category for promotions.
              'price_value' =>
                $product['price'] !== null
                ? (float) $product['price']
                : null,

              'category_id' =>
                (int) $product['category_id'],

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
                <?php foreach ($currentPromotions as $promotion): ?>
                  <?php if (promotion_applies_to($promotion, (int) $product['id'], (int) $product['category_id'])): ?>
                    <p class="product-promo-tag" title="<?= e($promotion['name']) ?>">Promo <?= e(promotion_short_label($promotion)) ?></p>
                  <?php endif; ?>
                <?php endforeach; ?>
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
        </div>
        <p class="modal-size-hint">Puedes marcar varias tallas del mismo modelo.</p>
        <div class="modal-quantity" id="modalQuantity" hidden>
          <span class="modal-option-title">Cantidad por talla</span>
          <div class="modal-size-lines" id="modalSizeLines"></div>
        </div>
        <button class="modal-buy-button" id="modalBuyButton" type="button" disabled>
          SELECCIONA UNA O VARIAS TALLAS
        </button>
        <p class="modal-cart-added" id="modalCartAdded" hidden>
          Agregado al carrito. <button type="button" data-open-cart>Ver carrito y enviar pedido</button>
        </p>
        <div class="modal-wholesale-order" id="modalWholesaleOrder" hidden>
          <div class="modal-wholesale-panel" id="modalWholesalePanel" hidden>
            <span class="modal-option-title">
              Pedido mayorista: <strong>cantidad por talla</strong>
            </span>
            <div class="modal-wholesale-sizes" id="modalWholesaleSizes"></div>
            <p class="modal-wholesale-summary" id="modalWholesaleSummary"></p>
            <button class="modal-buy-button modal-wholesale-button" id="modalWholesaleButton" type="button" disabled>
              AGREGAR AL CARRITO
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
  <button class="cart-fab" id="cartFab" type="button" aria-label="Ver carrito" aria-controls="cartPanel" hidden>
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2.5 3.5h2.6l2.4 11.2a1.6 1.6 0 0 0 1.6 1.3h8.6a1.6 1.6 0 0 0 1.6-1.2l1.6-6.8H6.1"/></svg>
    <span>Ver pedido</span>
    <strong class="cart-count" id="cartFabCount">0</strong>
  </button>

  <div class="cart-panel" id="cartPanel" hidden>
    <div class="cart-panel-backdrop" data-close-cart></div>
    <aside class="cart-panel-dialog" role="dialog" aria-modal="true" aria-labelledby="cartTitle">
      <div class="cart-panel-header">
        <div>
          <span class="eyebrow">CARRITO</span>
          <h2 id="cartTitle">Tu pedido</h2>
        </div>
        <button class="cart-panel-close" type="button" data-close-cart aria-label="Cerrar">×</button>
      </div>
      <div class="cart-panel-body">
        <div class="cart-empty" id="cartEmpty">
          <p>Tu carrito está vacío.</p>
          <p>Abre un modelo, elige color y talla, y toca <strong>AGREGAR AL CARRITO</strong>. Puedes agregar varios modelos.</p>
          <button class="button button-dark" type="button" data-close-cart>Ver catálogo</button>
        </div>
        <ul class="cart-lines" id="cartLines"></ul>
        <div class="cart-summary" id="cartSummary" hidden></div>
        <div class="cart-customer" id="cartCustomer" hidden>
          <label>
            <span>Tu nombre (opcional)</span>
            <input type="text" id="cartName" maxlength="80" autocomplete="name">
          </label>
          <label>
            <span>Tu WhatsApp o teléfono (opcional)</span>
            <input type="tel" id="cartPhone" maxlength="40" autocomplete="tel" inputmode="tel">
          </label>
          <input type="text" id="cartWebsite" name="website" tabindex="-1" autocomplete="off" class="cart-hp" aria-hidden="true">
          <label>
            <span>Nota para la tienda (opcional)</span>
            <textarea id="cartNote" rows="2" maxlength="300" placeholder="Ej.: entrega en Managua, otro color..."></textarea>
          </label>
        </div>
      </div>
      <div class="cart-panel-footer" id="cartFooter" hidden>
        <p class="cart-sent" id="cartSent" hidden>
          Abrimos WhatsApp con tu pedido. ¿Ya lo enviaste?
          <button type="button" id="cartClearSent">Vaciar carrito</button>
        </p>
        <p class="cart-footer-total"><span>Total estimado</span><strong id="cartFooterTotal"></strong></p>
        <button class="modal-buy-button cart-send-button" id="cartSend" type="button">
          ENVIAR PEDIDO POR WHATSAPP
        </button>
        <div class="cart-footer-links">
          <button type="button" data-close-cart>Seguir comprando</button>
          <button type="button" id="cartClear">Vaciar carrito</button>
        </div>
      </div>
    </aside>
  </div>

  <script>

    const BUSINESS_NAME = <?= json_encode(business_name(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    const WHATSAPP_NUMBER = <?= json_encode(whatsapp_digits()) ?>;
    const CURRENCY_SYMBOL = <?= json_encode(currency_symbol(), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
    // Current promotions, to estimate the cart total (the shop confirms it).
    const CART_PROMOTIONS = <?= json_encode(array_map('promotion_for_js', $currentPromotions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

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
                const modalQuantity =
          document.getElementById("modalQuantity");

        const modalSizeLines =
          document.getElementById("modalSizeLines");

        const modalCartAdded =
          document.getElementById("modalCartAdded");

        // Sizes chosen in retail mode: size -> {item, quantity}.
        const selectedSizes = new Map();

        const retailPieces = [
          modalSizeOptions.closest(".modal-option"),
          document.querySelector(".modal-size-hint"),
          modalBuyButton
        ];

                function setWholesaleOpen(isOpen) {
          wholesalePanel.hidden = !isOpen;
          modalQuantity.hidden = isOpen || selectedSizes.size === 0;

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

          if (!product || wholesaleButton.disabled) {
            return;
          }

          lines.forEach(line => {
            addToCart(product, modalColorName.textContent, line.size, line.quantity, null, true);
          });

          wholesaleSizes.querySelectorAll("input").forEach(input => {
            input.value = "";
          });

          updateWholesaleSummary();
          showCartAdded();
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
            "SELECCIONA UNA O VARIAS TALLAS";

          selectedSizes.clear();
          renderSelectedSizes();


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

                  // Several sizes of the same model: each click toggles one.
                  const key = String(item.size);

                  if (selectedSizes.has(key)) {
                    selectedSizes.delete(key);
                  } else {
                    selectedSizes.set(key, { item: item, quantity: 1 });
                  }

                  button.classList.toggle("is-selected", selectedSizes.has(key));
                  renderSelectedSizes();

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

                    modalCartAdded.hidden = true;
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
        | Cart (carrito): several models, one WhatsApp message
        |--------------------------------------------------------------------------
        |
        | Lines live in this browser (localStorage). Prices follow the
        | sale rules: a wholesale model gets its wholesale price when its
        | pairs reach the minimum; otherwise the best current promotion
        | (never stacked with wholesale) is estimated. First-purchase
        | promotions are only mentioned, the shop confirms them.
        |
        */

        const CART_KEY = "catalogCart:" + location.pathname;

        const cartPanel = document.getElementById("cartPanel");
        const cartLinesList = document.getElementById("cartLines");
        const cartEmpty = document.getElementById("cartEmpty");
        const cartSummary = document.getElementById("cartSummary");
        const cartCustomer = document.getElementById("cartCustomer");
        const cartFooter = document.getElementById("cartFooter");
        const cartSent = document.getElementById("cartSent");
        const cartFab = document.getElementById("cartFab");
        const cartNameInput = document.getElementById("cartName");
        const cartNoteInput = document.getElementById("cartNote");
        const cartPhoneInput = document.getElementById("cartPhone");
        const ORDER_URL = <?= json_encode(APP_BASE_URL . '/order-submit.php', JSON_UNESCAPED_SLASHES) ?>;

        const money = value =>
          CURRENCY_SYMBOL + Number(value).toLocaleString("en-US", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
          });

        function readCart() {
          try {
            const stored = JSON.parse(localStorage.getItem(CART_KEY) || "[]");
            return Array.isArray(stored) ? stored : [];
          } catch (error) {
            return [];
          }
        }

        let cart = readCart();

        function saveCart() {
          try {
            localStorage.setItem(CART_KEY, JSON.stringify(cart));
          } catch (error) {
            // Private mode: the cart still works until the page closes.
          }
        }

        // Refresh saved lines with today's prices and stock.
        const catalogProducts = {};

        cards.forEach(card => {
          const product = getProductData(card);

          if (product) {
            catalogProducts[product.id] = product;
          }
        });

        function sizeStock(product, colorName, size) {
          const color = (product.colors || []).find(item => item.name === colorName);
          const sizeItem = color ? (color.sizes || []).find(item => String(item.size) === String(size)) : null;

          return sizeItem ? Number(sizeItem.stock) || 0 : 0;
        }

        cart = cart
          .filter(line => catalogProducts[line.id])
          .map(line => {
            const product = catalogProducts[line.id];

            return Object.assign({}, line, {
              name: product.name,
              code: product.code,
              image: product.image,
              price: product.price_value,
              category_id: product.category_id,
              wholesale_price: product.wholesale_price,
              wholesale_min: product.wholesale_min,
              stock: line.wholesale ? null : sizeStock(product, line.color, line.size)
            });
          })
          .filter(line => line.wholesale || line.stock > 0)
          .map(line => line.wholesale ? line : Object.assign(line, {
            quantity: Math.min(line.quantity, line.stock)
          }));

        saveCart();

        function addToCart(product, colorName, size, quantity, stock, isWholesale) {
          const key = [product.id, colorName, size, isWholesale ? "w" : "r"].join("|");
          const existing = cart.find(line => line.key === key);

          quantity = Math.max(1, parseInt(quantity, 10) || 1);

          if (existing) {
            existing.quantity += quantity;
          } else {
            cart.push({
              key: key,
              id: product.id,
              code: product.code,
              name: product.name,
              image: product.image,
              color: colorName,
              size: String(size),
              quantity: quantity,
              stock: isWholesale ? null : stock,
              wholesale: Boolean(isWholesale),
              price: product.price_value,
              category_id: product.category_id,
              wholesale_price: product.wholesale_price,
              wholesale_min: product.wholesale_min
            });
          }

          cart.forEach(line => {
            if (!line.wholesale && line.stock !== null) {
              line.quantity = Math.min(line.quantity, Math.max(1, line.stock));
            }
          });

          cartSent.hidden = true;
          saveCart();
          renderCart();
        }

        function showCartAdded() {
          modalCartAdded.hidden = false;
          cartFab.classList.remove("is-bumped");
          void cartFab.offsetWidth;
          cartFab.classList.add("is-bumped");
        }

        /*
        | Pricing
        */

        function promotionApplies(promotion, line) {
          if (promotion.products && promotion.products.length > 0) {
            return promotion.products.includes(String(line.code).trim().toLowerCase());
          }

          return promotion.categories.length === 0 ||
            promotion.categories.includes(Number(line.category_id));
        }

        function promotionDiscount(promotion, lines) {
          const units = [];

          lines.forEach(line => {
            if (line.price === null || !promotionApplies(promotion, line)) {
              return;
            }

            for (let i = 0; i < line.quantity; i++) {
              units.push(Number(line.price));
            }
          });

          if (promotion.type === "percent") {
            return Math.round(units.reduce((sum, value) => sum + value, 0) * promotion.percent) / 100;
          }

          const size = Number(promotion.bundle_qty);

          if (size < 1 || units.length < size) {
            return 0;
          }

          units.sort((a, b) => b - a);

          const bundles = Math.floor(units.length / size);
          const bundled = units.slice(0, bundles * size).reduce((sum, value) => sum + value, 0);

          return Math.max(0, Math.round((bundled - bundles * promotion.bundle_price) * 100) / 100);
        }

        function cartPricing() {
          const pairsByProduct = {};

          cart.forEach(line => {
            pairsByProduct[line.id] = (pairsByProduct[line.id] || 0) + line.quantity;
          });

          const pairs = cart.reduce((sum, line) => sum + line.quantity, 0);
          const priced = cart.filter(line => line.price !== null || line.wholesale_price);
          const unpriced = cart.filter(line => line.price === null && !line.wholesale_price);

          const wholesaleOk = id => {
            const line = cart.find(item => item.id === id);
            return Boolean(line && line.wholesale_price) &&
              pairsByProduct[id] >= Math.max(1, Number(line.wholesale_min) || 1);
          };

          const retailUnit = line => line.price !== null ? Number(line.price) : Number(line.wholesale_price);
          const retailTotal = priced.reduce((sum, line) => sum + retailUnit(line) * line.quantity, 0);

          // Option 1: wholesale prices for the models that reach the minimum.
          const wholesaleTotal = priced.reduce((sum, line) =>
            sum + (wholesaleOk(line.id) ? Number(line.wholesale_price) : retailUnit(line)) * line.quantity, 0);

          // Option 2: the best promotion (not first purchase) on retail prices.
          let bestPromotion = null;
          let firstPurchase = null;

          CART_PROMOTIONS.forEach(promotion => {
            const discount = promotionDiscount(promotion, priced);

            if (discount <= 0) {
              return;
            }

            if (promotion.first_purchase_only) {
              if (!firstPurchase || discount > firstPurchase.discount) {
                firstPurchase = { promotion: promotion, discount: discount };
              }
            } else if (!bestPromotion || discount > bestPromotion.discount) {
              bestPromotion = { promotion: promotion, discount: discount };
            }
          });

          const promotionTotal = bestPromotion ? retailTotal - bestPromotion.discount : retailTotal;
          const useWholesale = wholesaleTotal < retailTotal - 0.004 && wholesaleTotal <= promotionTotal;
          const usePromotion = !useWholesale && bestPromotion !== null;
          const total = useWholesale ? wholesaleTotal : promotionTotal;

          const unitPrice = line =>
            useWholesale && wholesaleOk(line.id) ? Number(line.wholesale_price) : retailUnit(line);

          // Wholesale models still short of the minimum.
          const wholesaleHints = [];

          Object.keys(pairsByProduct).forEach(id => {
            const line = cart.find(item => String(item.id) === id);
            const minimum = Math.max(1, Number(line.wholesale_min) || 1);

            const missing = minimum - pairsByProduct[id];
            const askedWholesale = cart.some(item => String(item.id) === id && item.wholesale);

            if (line.wholesale_price && missing > 0 && askedWholesale) {
              wholesaleHints.push(
                `${line.name}: ${missing === 1 ? "falta 1 par" : `faltan ${missing} pares`} para precio mayorista (${money(line.wholesale_price)} c/u)`
              );
            }
          });

          const firstPurchaseHint =
            firstPurchase && retailTotal - firstPurchase.discount < total - 0.004
              ? firstPurchase
              : null;

          return {
            pairs, retailTotal, total, unpriced, useWholesale, usePromotion,
            bestPromotion, firstPurchaseHint, wholesaleHints, unitPrice,
            wholesaleOk
          };
        }

        /*
        | Render
        */

        function lineElement(line, pricing) {
          const item = document.createElement("li");
          const unit = line.price === null && !line.wholesale_price ? null : pricing.unitPrice(line);
          const atLimit = !line.wholesale && line.stock !== null && line.quantity >= line.stock;

          item.className = "cart-line";
          item.innerHTML = `
            <img class="cart-line-image" alt="">
            <div class="cart-line-info">
              <p class="cart-line-code"></p>
              <h3 class="cart-line-name"></h3>
              <p class="cart-line-meta"></p>
              <div class="cart-line-bottom">
                <div class="cart-stepper">
                  <button type="button" data-cart-step="-1" aria-label="Menos">−</button>
                  <span class="cart-stepper-value"></span>
                  <button type="button" data-cart-step="1" aria-label="Más"></button>
                </div>
                <p class="cart-line-price"></p>
              </div>
            </div>
            <button class="cart-line-remove" type="button" data-cart-remove aria-label="Quitar">×</button>`;

          item.dataset.key = line.key;
          item.querySelector(".cart-line-image").src = line.image;
          item.querySelector(".cart-line-code").textContent = line.code;
          item.querySelector(".cart-line-name").textContent = line.name;
          item.querySelector(".cart-line-meta").textContent =
            `Color: ${line.color} · Talla: ${line.size}` + (line.wholesale ? " · Al por mayor" : "");
          item.querySelector(".cart-stepper-value").textContent = line.quantity;

          const plus = item.querySelector('[data-cart-step="1"]');
          plus.textContent = "+";
          plus.disabled = atLimit;
          plus.title = atLimit ? "No hay más pares en esta talla" : "";

          item.querySelector(".cart-line-price").innerHTML = unit === null
            ? "Precio a consultar"
            : `<strong>${money(unit * line.quantity)}</strong><small>${line.quantity} × ${money(unit)}</small>`;

          return item;
        }

        function renderCart() {
          const pricing = cartPricing();
          const isEmpty = cart.length === 0;

          ["cartCount", "cartFabCount"].forEach(id => {
            const badge = document.getElementById(id);
            badge.textContent = pricing.pairs;
            badge.hidden = isEmpty;
          });

          cartFab.hidden = isEmpty;
          cartEmpty.hidden = !isEmpty;
          cartSummary.hidden = isEmpty;
          cartCustomer.hidden = isEmpty;
          cartFooter.hidden = isEmpty;

          cartLinesList.innerHTML = "";
          cart.forEach(line => cartLinesList.appendChild(lineElement(line, pricing)));

          if (isEmpty) {
            return;
          }

          const rows = [
            ["Pares", String(pricing.pairs)],
            ["Subtotal", money(pricing.retailTotal)]
          ];

          if (pricing.useWholesale) {
            rows.push(["Precio mayorista", "−" + money(pricing.retailTotal - pricing.total)]);
          } else if (pricing.usePromotion) {
            rows.push([`Promo: ${pricing.bestPromotion.promotion.name}`, "−" + money(pricing.bestPromotion.discount)]);
          }

          const notes = [...pricing.wholesaleHints];

          if (pricing.firstPurchaseHint) {
            notes.push(
              `¿Es tu primera compra? Con "${pricing.firstPurchaseHint.promotion.name}" ahorras ${money(pricing.firstPurchaseHint.discount)}. La tienda lo confirma.`
            );
          }

          if (pricing.unpriced.length > 0) {
            notes.push("Algunos modelos tienen precio a consultar y no están en el total.");
          }

          cartSummary.innerHTML = "";

          rows.forEach(([label, value]) => {
            const row = document.createElement("p");
            row.className = "cart-summary-row";
            row.innerHTML = "<span></span><span></span>";
            row.children[0].textContent = label;
            row.children[1].textContent = value;
            cartSummary.appendChild(row);
          });

          const totalRow = document.createElement("p");
          totalRow.className = "cart-summary-row cart-summary-total";
          totalRow.innerHTML = "<span>Total estimado</span><strong></strong>";
          totalRow.querySelector("strong").textContent = money(pricing.total);
          document.getElementById("cartFooterTotal").textContent = money(pricing.total);
          cartSummary.appendChild(totalRow);

          notes.forEach(text => {
            const note = document.createElement("p");
            note.className = "cart-summary-note";
            note.textContent = text;
            cartSummary.appendChild(note);
          });
        }

        /*
        | WhatsApp message
        */

        function cartMessage(orderNumber) {
          const pricing = cartPricing();
          const lines = [
            orderNumber
              ? `Hola ${BUSINESS_NAME}, quiero hacer este pedido (${orderNumber}):`
              : `Hola ${BUSINESS_NAME}, quiero hacer este pedido:`,
            ""
          ];

          cart.forEach((line, index) => {
            const unit = line.price === null && !line.wholesale_price ? null : pricing.unitPrice(line);

            lines.push(`${index + 1}. ${line.name} (${line.code})`);
            lines.push(`   Color: ${line.color} · Talla: ${line.size} · Cantidad: ${line.quantity}`);
            lines.push(unit === null
              ? "   Precio: a consultar"
              : `   ${money(unit)} c/u = ${money(unit * line.quantity)}` +
                (pricing.useWholesale && pricing.wholesaleOk(line.id) ? " (mayorista)" : ""));
          });

          lines.push("");
          lines.push(`Pares: ${pricing.pairs}`);
          lines.push(`Subtotal: ${money(pricing.retailTotal)}`);

          if (pricing.useWholesale) {
            lines.push(`Precio mayorista: −${money(pricing.retailTotal - pricing.total)}`);
          } else if (pricing.usePromotion) {
            lines.push(`Promoción "${pricing.bestPromotion.promotion.name}": −${money(pricing.bestPromotion.discount)}`);
          }

          lines.push(`Total estimado: ${money(pricing.total)}`);

          if (pricing.firstPurchaseHint) {
            lines.push(`Es mi primera compra, ¿aplica "${pricing.firstPurchaseHint.promotion.name}"?`);
          }

          const name = cartNameInput.value.trim();
          const phone = cartPhoneInput.value.trim();
          const note = cartNoteInput.value.trim();

          if (name || phone || note) {
            lines.push("");
          }

          if (name) {
            lines.push(`Nombre: ${name}`);
          }

          if (phone) {
            lines.push(`Teléfono: ${phone}`);
          }

          if (note) {
            lines.push(`Nota: ${note}`);
          }

          lines.push("");
          lines.push("¿Está disponible?");

          return lines.join("\n");
        }

        /*
        | Events
        */

        function openCart() {
          renderCart();
          cartPanel.hidden = false;
          document.body.classList.add("modal-open");
        }

        function closeCart() {
          cartPanel.hidden = true;

          if (modal.hidden) {
            document.body.classList.remove("modal-open");
          }
        }

        function clearCart() {
          cart = [];
          cartSent.hidden = true;
          saveCart();
          renderCart();
        }

        document.getElementById("cartToggle").addEventListener("click", openCart);
        cartFab.addEventListener("click", openCart);

        document.addEventListener("click", event => {
          if (event.target.closest("[data-open-cart]")) {
            closeModal();
            openCart();
            return;
          }

          if (event.target.closest("[data-close-cart]")) {
            closeCart();
            return;
          }

          const lineItem = event.target.closest(".cart-line");

          if (!lineItem) {
            return;
          }

          const line = cart.find(item => item.key === lineItem.dataset.key);
          const step = event.target.closest("[data-cart-step]");

          if (!line) {
            return;
          }

          if (event.target.closest("[data-cart-remove]")) {
            cart = cart.filter(item => item !== line);
          } else if (step) {
            line.quantity += Number(step.dataset.cartStep);

            if (!line.wholesale && line.stock !== null) {
              line.quantity = Math.min(line.quantity, line.stock);
            }

            if (line.quantity < 1) {
              cart = cart.filter(item => item !== line);
            }
          } else {
            return;
          }

          saveCart();
          renderCart();
        });

        document.addEventListener("keydown", event => {
          if (event.key === "Escape" && !cartPanel.hidden) {
            closeCart();
          }
        });

        document.getElementById("cartClear").addEventListener("click", () => {
          if (confirm("¿Vaciar el carrito?")) {
            clearCart();
          }
        });

        document.getElementById("cartClearSent").addEventListener("click", clearCart);

        // Saves the order (Administración > Pedidos) and then opens
        // WhatsApp with its number. If saving fails or takes more than
        // 6 seconds, WhatsApp opens anyway without a number.
        const cartSendButton = document.getElementById("cartSend");

        async function saveOrder() {
          const controller = "AbortController" in window ? new AbortController() : null;
          const timer = controller ? setTimeout(() => controller.abort(), 6000) : null;

          try {
            const response = await fetch(ORDER_URL, {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({
                name: cartNameInput.value.trim(),
                phone: cartPhoneInput.value.trim(),
                note: cartNoteInput.value.trim(),
                website: document.getElementById("cartWebsite").value,
                lines: cart.map(line => ({
                  id: line.id,
                  color: line.color,
                  size: line.size,
                  quantity: line.quantity,
                  wholesale: line.wholesale
                }))
              }),
              signal: controller ? controller.signal : undefined
            });
            const result = await response.json();

            if (response.status === 422 && result.error) {
              return { error: result.error };
            }

            return result.ok ? { number: result.number } : {};
          } catch (error) {
            return {};
          } finally {
            if (timer) {
              clearTimeout(timer);
            }
          }
        }

        cartSendButton.addEventListener("click", async () => {
          if (cart.length === 0 || cartSendButton.disabled) {
            return;
          }

          if (!WHATSAPP_NUMBER) {
            alert("La tienda todavía no configuró su número de WhatsApp.");
            return;
          }

          // Opened now (inside the tap) so phones do not block it.
          const whatsappWindow = window.open("", "_blank");

          cartSendButton.disabled = true;
          cartSendButton.textContent = "GUARDANDO PEDIDO...";

          const saved = await saveOrder();

          cartSendButton.disabled = false;
          cartSendButton.textContent = "ENVIAR PEDIDO POR WHATSAPP";

          if (saved.error) {
            if (whatsappWindow) {
              whatsappWindow.close();
            }

            alert(saved.error);
            return;
          }

          const url = "https://wa.me/" + WHATSAPP_NUMBER + "?text=" + encodeURIComponent(cartMessage(saved.number));

          if (whatsappWindow) {
            whatsappWindow.opener = null;
            whatsappWindow.location.href = url;
          } else {
            window.location.href = url;
          }

          cartSent.hidden = false;
        });

        // Retail: one quantity per chosen size and "AGREGAR AL CARRITO".
        function renderSelectedSizes() {
          const lines = Array.from(selectedSizes.values());
          const pairs = lines.reduce((sum, line) => sum + line.quantity, 0);

          modalSizeLines.innerHTML = "";

          lines.forEach(line => {
            const stock = Math.max(1, Number(line.item.stock) || 1);
            const row = document.createElement("div");

            row.className = "modal-size-line";
            row.dataset.size = String(line.item.size);
            row.innerHTML = `
              <span class="modal-size-line-name"></span>
              <div class="cart-stepper">
                <button type="button" data-size-step="-1" aria-label="Menos">−</button>
                <span class="cart-stepper-value"></span>
                <button type="button" data-size-step="1" aria-label="Más">+</button>
              </div>`;
            row.querySelector(".modal-size-line-name").textContent = `Talla ${line.item.size}`;
            row.querySelector(".cart-stepper-value").textContent = line.quantity;
            row.querySelector('[data-size-step="1"]').disabled = line.quantity >= stock;
            modalSizeLines.appendChild(row);
          });

          modalQuantity.hidden = lines.length === 0 || !wholesalePanel.hidden;
          modalSizeName.textContent = lines.length === 0
            ? "Selecciona"
            : lines.map(line => line.item.size).join(", ");
          modalBuyButton.disabled = lines.length === 0;
          modalBuyButton.textContent = lines.length === 0
            ? "SELECCIONA UNA O VARIAS TALLAS"
            : `AGREGAR AL CARRITO · ${pairs} ${pairs === 1 ? "PAR" : "PARES"}`;
        }

        modalSizeLines.addEventListener("click", event => {
          const step = event.target.closest("[data-size-step]");
          const row = event.target.closest(".modal-size-line");

          if (!step || !row) {
            return;
          }

          const line = selectedSizes.get(row.dataset.size);
          const stock = Math.max(1, Number(line.item.stock) || 1);

          line.quantity += Number(step.dataset.sizeStep);

          if (line.quantity < 1) {
            selectedSizes.delete(row.dataset.size);
            modalSizeOptions.querySelectorAll(".modal-size-button").forEach(button => {
              if (button.textContent.trim() === row.dataset.size) {
                button.classList.remove("is-selected");
              }
            });
          } else {
            line.quantity = Math.min(line.quantity, stock);
          }

          renderSelectedSizes();
        });

        modalBuyButton.addEventListener("click", () => {
          if (!currentProduct || modalBuyButton.disabled || selectedSizes.size === 0) {
            return;
          }

          selectedSizes.forEach(line => {
            addToCart(
              currentProduct,
              modalColorName.textContent,
              line.item.size,
              line.quantity,
              Math.max(1, Number(line.item.stock) || 1),
              false
            );
          });

          selectedSizes.clear();
          modalSizeOptions.querySelectorAll(".modal-size-button").forEach(button => {
            button.classList.remove("is-selected");
          });
          renderSelectedSizes();
          showCartAdded();
        });

        renderCart();


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