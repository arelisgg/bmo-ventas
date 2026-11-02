/* =========================================================
   PROMOCIONES
   promotions: cada promoción administrable. Dos tipos:
   - bundle: N productos de las categorías elegidas por un
     precio fijo (ej.: 3 flats por C$1000).
   - percent: % de descuento en las categorías elegidas,
     opcionalmente solo en la primera compra del cliente.
   promotion_categories: categorías donde aplica (sin filas =
   todas). En sales se guarda la promoción aceptada y cuánto
   descontó; sales.discount sigue siendo el descuento total.
   ========================================================= */

CREATE TABLE IF NOT EXISTS promotions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(150) NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'percent',

    bundle_qty INT UNSIGNED DEFAULT NULL,
    bundle_price DECIMAL(10,2) DEFAULT NULL,
    percent DECIMAL(5,2) DEFAULT NULL,

    first_purchase_only TINYINT(1) NOT NULL DEFAULT 0,

    starts_on DATE DEFAULT NULL,
    ends_on DATE DEFAULT NULL,

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_promotions_active (is_active)
);

CREATE TABLE IF NOT EXISTS promotion_categories (
    promotion_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,

    PRIMARY KEY (promotion_id, category_id),

    CONSTRAINT fk_promotion_categories_promotion
        FOREIGN KEY (promotion_id) REFERENCES promotions(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_promotion_categories_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
        ON DELETE CASCADE ON UPDATE CASCADE
);

ALTER TABLE sales
    ADD COLUMN promotion_id INT UNSIGNED DEFAULT NULL
        AFTER discount,
    ADD COLUMN promotion_name VARCHAR(150) DEFAULT NULL
        AFTER promotion_id,
    ADD COLUMN promotion_discount DECIMAL(10,2) NOT NULL DEFAULT 0
        AFTER promotion_name;
