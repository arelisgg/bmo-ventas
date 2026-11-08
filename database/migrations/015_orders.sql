/* =========================================================
   PEDIDOS DEL CATÁLOGO
   orders: cada pedido que el cliente arma en el carrito del
   catálogo y envía por WhatsApp. Se guarda antes de abrir
   WhatsApp, con el número P-00001 que va en el mensaje.
   Se convierte en venta (sale_id) y/o en pedido al taller
   (workshop_order_id) desde Administración > Pedidos.
   order_items: copia de cada línea (modelo, color, talla,
   cantidad y precio estimado) aunque el producto cambie.
   ========================================================= */

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    customer_name VARCHAR(150) DEFAULT NULL,
    customer_phone VARCHAR(40) DEFAULT NULL,
    customer_note TEXT DEFAULT NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'new',

    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    pricing_note VARCHAR(255) DEFAULT NULL,
    is_wholesale TINYINT(1) NOT NULL DEFAULT 0,

    admin_notes TEXT DEFAULT NULL,

    sale_id INT UNSIGNED DEFAULT NULL,
    workshop_order_id INT UNSIGNED DEFAULT NULL,

    client_ip VARCHAR(64) DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_orders_status (status),
    KEY idx_orders_created (created_at),

    CONSTRAINT fk_orders_sale
        FOREIGN KEY (sale_id) REFERENCES sales(id)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT fk_orders_workshop
        FOREIGN KEY (workshop_order_id) REFERENCES workshop_orders(id)
        ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED DEFAULT NULL,
    variant_id INT UNSIGNED DEFAULT NULL,

    product_code VARCHAR(100) NOT NULL,
    product_name VARCHAR(180) NOT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    color_name VARCHAR(100) NOT NULL,
    size VARCHAR(20) NOT NULL,

    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10,2) DEFAULT NULL,
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_wholesale TINYINT(1) NOT NULL DEFAULT 0,

    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT fk_order_items_variant
        FOREIGN KEY (variant_id) REFERENCES product_variants(id)
        ON DELETE SET NULL ON UPDATE CASCADE
);
