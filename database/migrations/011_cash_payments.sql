/* =========================================================
   CAJA Y PAGOS
   cash_sessions: una caja abierta por día (fondo inicial,
   cierre con lo contado). sale_payments: cada pago o abono
   de una venta, con su método. cash_movements: entradas y
   salidas de efectivo que no son ventas.
   ========================================================= */

CREATE TABLE IF NOT EXISTS cash_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    opened_at DATETIME NOT NULL,
    opened_by INT UNSIGNED DEFAULT NULL,
    opening_amount DECIMAL(10,2) NOT NULL DEFAULT 0,

    closed_at DATETIME DEFAULT NULL,
    closed_by INT UNSIGNED DEFAULT NULL,
    expected_amount DECIMAL(10,2) DEFAULT NULL,
    counted_amount DECIMAL(10,2) DEFAULT NULL,
    notes TEXT DEFAULT NULL,

    KEY idx_cash_sessions_closed (closed_at),

    CONSTRAINT fk_cash_sessions_opened_by
        FOREIGN KEY (opened_by) REFERENCES admins(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_cash_sessions_closed_by
        FOREIGN KEY (closed_by) REFERENCES admins(id)
        ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS sale_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    sale_id INT UNSIGNED NOT NULL,
    cash_session_id INT UNSIGNED DEFAULT NULL,

    method VARCHAR(30) NOT NULL DEFAULT 'cash',
    amount DECIMAL(10,2) NOT NULL,
    note VARCHAR(190) DEFAULT NULL,

    admin_id INT UNSIGNED DEFAULT NULL,
    paid_at DATETIME NOT NULL,

    KEY idx_sale_payments_paid_at (paid_at),

    CONSTRAINT fk_sale_payments_sale
        FOREIGN KEY (sale_id) REFERENCES sales(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sale_payments_session
        FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_sale_payments_admin
        FOREIGN KEY (admin_id) REFERENCES admins(id)
        ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS cash_movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    cash_session_id INT UNSIGNED NOT NULL,
    type VARCHAR(10) NOT NULL DEFAULT 'out',
    amount DECIMAL(10,2) NOT NULL,
    reason VARCHAR(190) NOT NULL,

    admin_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL,

    CONSTRAINT fk_cash_movements_session
        FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cash_movements_admin
        FOREIGN KEY (admin_id) REFERENCES admins(id)
        ON DELETE SET NULL ON UPDATE CASCADE
);

/* Las ventas pagadas de antes quedan con un pago por el total. */

INSERT INTO sale_payments (sale_id, method, amount, admin_id, paid_at)
SELECT s.id, s.payment_method, s.total, s.admin_id, s.sold_at
FROM sales s
WHERE s.status = 'paid'
  AND s.total > 0
  AND NOT EXISTS (SELECT 1 FROM sale_payments p WHERE p.sale_id = s.id);
