/* =========================================================
   MODELOS DE CADA PROMOCIÓN
   promotion_products: si una promoción tiene modelos elegidos,
   aplica solo a esos modelos (las categorías no se usan).
   Sin filas = como antes (sus categorías, o todo).
   ========================================================= */

CREATE TABLE IF NOT EXISTS promotion_products (
    promotion_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,

    PRIMARY KEY (promotion_id, product_id),
    KEY idx_promotion_products_product (product_id),

    CONSTRAINT fk_promotion_products_promotion
        FOREIGN KEY (promotion_id)
        REFERENCES promotions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_promotion_products_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE CASCADE
);
