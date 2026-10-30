/* =========================================================
   INTENTOS DE INICIO DE SESIÓN
   Guarda los intentos fallidos para bloquear el inicio de
   sesión por 15 minutos después de 5 intentos.
   ========================================================= */

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,

    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_login_attempts_email (email),
    KEY idx_login_attempts_ip (ip_address),
    KEY idx_login_attempts_time (attempted_at)
);
