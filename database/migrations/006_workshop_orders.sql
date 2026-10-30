/* =========================================================
   PEDIDOS AL TALLER
   Ejecutar una vez. Guarda las órdenes que se le pasan al
   taller: cada modelo con su foto y la cantidad por color
   y talla.
   ========================================================= */

USE bmo_ventas;

CREATE TABLE IF NOT EXISTS workshop_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    reference VARCHAR(150) DEFAULT NULL,
    sale_id INT UNSIGNED DEFAULT NULL,

    due_date DATE DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    notes TEXT DEFAULT NULL,

    admin_id INT UNSIGNED DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_workshop_orders_status (status),

    CONSTRAINT fk_workshop_orders_sale
        FOREIGN KEY (sale_id)
        REFERENCES sales(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_workshop_orders_admin
        FOREIGN KEY (admin_id)
        REFERENCES admins(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS workshop_order_models (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED DEFAULT NULL,

    product_code VARCHAR(100) DEFAULT NULL,
    product_name VARCHAR(180) NOT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,

    sort_order INT NOT NULL DEFAULT 0,

    CONSTRAINT fk_workshop_models_order
        FOREIGN KEY (order_id)
        REFERENCES workshop_orders(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_workshop_models_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS workshop_order_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    model_id INT UNSIGNED NOT NULL,

    color_name VARCHAR(100) NOT NULL,
    size VARCHAR(20) NOT NULL,
    quantity INT UNSIGNED NOT NULL,

    CONSTRAINT fk_workshop_lines_model
        FOREIGN KEY (model_id)
        REFERENCES workshop_order_models(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);
