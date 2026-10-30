/* =========================================================
   ROLES DE USUARIO
   Ejecutar una vez, con la base de datos del sistema
   seleccionada. Cada usuario pasa a tener un rol: dueño,
   vendedor o bodega. Los usuarios que ya existen quedan
   como dueños.
   ========================================================= */

ALTER TABLE admins
    ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'owner'
        AFTER password;
