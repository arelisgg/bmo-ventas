<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/payment-functions.php';
require_once __DIR__ . '/../sales/_layout.php';
require_once __DIR__ . '/_session.php';

require_admin();

$sessionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$session = $sessionId !== false && payment_tables_exist($pdo) ? find_cash_session($pdo, $sessionId) : null;

if ($session === null) {
    header('Location: ' . APP_BASE_URL . '/admin/cash/');
    exit;
}

render_sales_layout_start('Cierre de caja', 'cash');

?>

<div class="page-header">
    <div>
        <span class="page-eyebrow">CAJA · <?= e(business_name()) ?></span>
        <h1 class="page-title">
            Cierre del <?= e(date('d/m/Y', strtotime((string) ($session['closed_at'] ?? $session['opened_at'])))) ?>
        </h1>
    </div>

    <div class="cash-no-print">
        <button class="admin-button" type="button" onclick="window.print()">IMPRIMIR</button>
        <a class="admin-button admin-button-outline" href="<?= e(APP_BASE_URL) ?>/admin/cash/">← VOLVER A CAJA</a>
    </div>
</div>

<?php if (isset($_GET['closed'])): ?>
    <div class="admin-notice admin-notice-success cash-no-print">Caja cerrada.</div>
<?php endif; ?>

<?php render_cash_session_detail($pdo, $session); ?>

<?php

render_sales_layout_end();
