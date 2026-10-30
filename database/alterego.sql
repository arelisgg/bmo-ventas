CREATE DATABASE IF NOT EXISTS bmo_ventas
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE bmo_ventas;


/* =========================================================
   CATEGORIES
   ========================================================= */

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,

    image VARCHAR(255) DEFAULT NULL,

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_coming_soon TINYINT(1) NOT NULL DEFAULT 0,

    sort_order INT NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);


/* =========================================================
   PRODUCTS
   ========================================================= */

CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    category_id INT UNSIGNED NOT NULL,

    code VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(180) NOT NULL UNIQUE,

    description TEXT DEFAULT NULL,

    price DECIMAL(10,2) DEFAULT NULL,

    main_image VARCHAR(255) DEFAULT NULL,

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    is_new TINYINT(1) NOT NULL DEFAULT 0,

    sort_order INT NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
);


/* =========================================================
   PRODUCT VARIANTS
   Color + Size + Stock
   ========================================================= */

CREATE TABLE product_variants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    product_id INT UNSIGNED NOT NULL,

    color_name VARCHAR(100) NOT NULL,
    color_hex VARCHAR(20) DEFAULT NULL,

    size VARCHAR(20) NOT NULL,

    stock INT UNSIGNED NOT NULL DEFAULT 0,

    sku VARCHAR(150) DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_variants_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    UNIQUE KEY unique_product_variant (
        product_id,
        color_name,
        size
    )
);


/* =========================================================
   PRODUCT IMAGES
   ========================================================= */

CREATE TABLE product_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    product_id INT UNSIGNED NOT NULL,

    image_path VARCHAR(255) NOT NULL,

    sort_order INT NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_images_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


/* =========================================================
   ADMIN USERS
   ========================================================= */

CREATE TABLE admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(120) NOT NULL,

    email VARCHAR(190) NOT NULL UNIQUE,

    password VARCHAR(255) NOT NULL,

    is_active TINYINT(1) NOT NULL DEFAULT 1,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);


/* =========================================================
   INITIAL CATEGORIES
   ========================================================= */

INSERT INTO categories
(
    name,
    slug,
    image,
    is_active,
    is_coming_soon,
    sort_order
)
VALUES

(
    'Flats',
    'flats',
    'assets/products/1.jpeg',
    1,
    0,
    1
),

(
    'Plataformas',
    'platforms',
    'assets/products/3.jpeg',
    1,
    0,
    2
),

(
    'Tacones',
    'heels',
    'assets/products/5.jpeg',
    1,
    0,
    3
),

(
    'Bolsos',
    'bags',
    'assets/coming-soon-bags.png',
    1,
    1,
    4
),

(
    'Accesorios',
    'accessories',
    'assets/coming-soon-accessories.png',
    1,
    1,
    5
);