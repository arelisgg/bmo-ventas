/* =========================================================
   CONFIGURACIÓN DEL NEGOCIO
   Ejecutar una vez, con la base de datos del sistema
   seleccionada. Guarda el nombre, logo, contacto, moneda e
   impuestos que se editan en Administración > Configuración.
   ========================================================= */

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
    setting_value TEXT DEFAULT NULL,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);
