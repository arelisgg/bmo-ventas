/* =========================================================
   FOTO DE REFERENCIA EN PRODUCTOS POR ENCARGO
   Ejecutar una vez, después de 002_sales_custom_items.sql.
   ========================================================= */

USE bmo_ventas;

ALTER TABLE sale_items
    ADD COLUMN image_path VARCHAR(255) DEFAULT NULL
        AFTER size;
