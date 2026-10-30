/* =========================================================
   VENTAS POR ENCARGO / SIN INVENTARIO
   Ejecutar una vez, después de 001_sales.sql.
   ========================================================= */

USE bmo_ventas;

ALTER TABLE sale_items
    ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'inventory'
        AFTER sale_id;
