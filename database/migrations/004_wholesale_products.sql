/* =========================================================
   PRODUCTOS MAYORISTAS
   Ejecutar una vez. Agrega el precio mayorista y la cantidad
   mínima a cada producto.
   ========================================================= */

USE bmo_ventas;

ALTER TABLE products
    ADD COLUMN is_wholesale TINYINT(1) NOT NULL DEFAULT 0
        AFTER is_new,
    ADD COLUMN wholesale_price DECIMAL(10,2) DEFAULT NULL
        AFTER is_wholesale,
    ADD COLUMN wholesale_min_qty INT UNSIGNED DEFAULT NULL
        AFTER wholesale_price;
