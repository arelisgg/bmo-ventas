/* =========================================================
   FINANZAS
   Ejecutar una vez. Agrega el costo de cada producto, guarda
   el costo en cada venta y crea la tabla de gastos.
   ========================================================= */

USE bmo_ventas;

ALTER TABLE products
    ADD COLUMN cost_price DECIMAL(10,2) DEFAULT NULL
        AFTER price;

ALTER TABLE sale_items
    ADD COLUMN unit_cost DECIMAL(10,2) DEFAULT NULL
        AFTER unit_price;

CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    expense_date DATE NOT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'other',
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(30) DEFAULT NULL,
    notes TEXT DEFAULT NULL,

    admin_id INT UNSIGNED DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_expenses_date (expense_date),
    KEY idx_expenses_category (category),

    CONSTRAINT fk_expenses_admin
        FOREIGN KEY (admin_id)
        REFERENCES admins(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);
