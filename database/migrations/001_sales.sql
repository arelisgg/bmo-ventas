/* =========================================================
   VENTAS
   Ejecutar una vez sobre bmo_ventas (phpMyAdmin o CLI).
   ========================================================= */

USE bmo_ventas;


/* =========================================================
   SALES
   ========================================================= */

CREATE TABLE IF NOT EXISTS sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    sale_number VARCHAR(30) DEFAULT NULL UNIQUE,

    customer_name VARCHAR(150) DEFAULT NULL,
    customer_phone VARCHAR(40) DEFAULT NULL,

    channel VARCHAR(30) NOT NULL DEFAULT 'whatsapp',
    payment_method VARCHAR(30) NOT NULL DEFAULT 'cash',
    status VARCHAR(20) NOT NULL DEFAULT 'paid',

    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,

    notes TEXT DEFAULT NULL,

    admin_id INT UNSIGNED DEFAULT NULL,

    sold_at DATETIME NOT NULL,
    cancelled_at DATETIME DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_sales_sold_at (sold_at),
    KEY idx_sales_status (status),

    CONSTRAINT fk_sales_admin
        FOREIGN KEY (admin_id)
        REFERENCES admins(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);


/* =========================================================
   SALE ITEMS
   Guarda una copia del producto para conservar el historial
   aunque el producto se edite o se elimine.
   ========================================================= */

CREATE TABLE IF NOT EXISTS sale_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    sale_id INT UNSIGNED NOT NULL,

    product_id INT UNSIGNED DEFAULT NULL,
    variant_id INT UNSIGNED DEFAULT NULL,

    product_code VARCHAR(100) NOT NULL,
    product_name VARCHAR(180) NOT NULL,
    color_name VARCHAR(100) NOT NULL,
    size VARCHAR(20) NOT NULL,

    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_sale_items_sale
        FOREIGN KEY (sale_id)
        REFERENCES sales(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_sale_items_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_sale_items_variant
        FOREIGN KEY (variant_id)
        REFERENCES product_variants(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);
