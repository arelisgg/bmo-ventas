/* =========================================================
   TIPO DE DOCUMENTO EN VENTAS
   Ejecutar una vez. Cada venta puede ser factura de venta,
   prefactura o cotización. Las ventas que ya existen quedan
   como factura de venta.
   ========================================================= */

USE bmo_ventas;

ALTER TABLE sales
    ADD COLUMN doc_type VARCHAR(20) NOT NULL DEFAULT 'invoice'
        AFTER sale_number,
    ADD KEY idx_sales_doc_type (doc_type);
