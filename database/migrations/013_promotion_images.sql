/* =========================================================
   IMAGEN DE PROMOCIONES
   Imagen opcional de cada promoción (uploads/promotions/),
   que el catálogo muestra en la sección Promociones.
   ========================================================= */

ALTER TABLE promotions
    ADD COLUMN image_path VARCHAR(255) DEFAULT NULL
        AFTER name;
