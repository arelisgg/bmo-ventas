<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/payment-functions.php';
require_once __DIR__ . '/../sales/_layout.php';
require_once __DIR__ . '/_session.php';

require_admin();

$tablesReady = payment_tables_exist($pdo);
$cashUrl = APP_BASE_URL . '/admin/cash/';
$error = '';


/*
|--------------------------------------------------------------------------
| Open / Movement / Close
|--------------------------------------------------------------------------
*/

if ($tablesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $session = open_cash_session($pdo);

    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            throw new DomainException('Token de seguridad inválido. Recarga la página.');
        }

        $amount = parse_money_input($_POST['amount'] ?? '');

        if ($amount === null) {
            throw new DomainException('Escribe un monto válido.');
        }

        if ($action === 'open') {
            open_new_cash_session($pdo, $amount);

            header('Location: ' . $cashUrl . '?opened=1');
            exit;
        }

        if ($session === null) {
            throw new DomainException('Primero abre la caja.');
        }

        if ($action === 'movement') {
            add_cash_movement(
                $pdo,
                (int) $session['id'],
                (string) ($_POST['type'] ?? ''),
                $amount,
                trim((string) ($_POST['reason'] ?? ''))
            );

            header('Location: ' . $cashUrl . '?movement=1');
            exit;
        }

        if ($action === 'close') {
            if (trim((string) ($_POST['amount'] ?? '')) === '') {
                throw new DomainException('Escribe cuánto efectivo contaste.');
            }

            close_cash_session($pdo, $session, $amount, trim((string) ($_POST['notes'] ?? '')));

            header('Location: ' . $cashUrl . 'view.php?id=' . (int) $session['id'] . '&closed=1');
            exit;
        }
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    }
}

$session = $tablesReady ? open_cash_session($pdo) : null;
$history = $tablesReady ? recent_cash_sessions($pdo) : [];

render_sales_layout_start('Caja', 'cash');

?>

<div class="page-header">
    <div>
        <span class="page-eyebrow">CAJA</span>
        <h1 class="page-title"><?= $session === null ? 'Caja cerrada' : 'Caja abierta' ?></h1>
        <p class="page-description">
            Abre la caja con el fondo inicial al empezar el día. Los pagos de las ventas se suman solos,
            y al cerrar cuentas el efectivo para ver si cuadra.
        </p>
    </div>
</div>

<?php if (!$tablesReady): ?>

    <section class="empty-state">
        <h2>Falta actualizar la base de datos</h2>
        <p>
            En <a href="<?= e(APP_BASE_URL) ?>/admin/settings/">Configuración</a> pulsa
            <strong>ACTUALIZAR BASE DE DATOS</strong> (solo el dueño puede hacerlo). Luego recarga esta página.
        </p>
    </section>

<?php else: ?>

    <?php if (isset($_GET['opened'])): ?>
        <div class="admin-notice admin-notice-success">Caja abierta.</div>
    <?php elseif (isset($_GET['movement'])): ?>
        <div class="admin-notice admin-notice-success">Movimiento registrado.</div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="admin-notice admin-notice-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($session === null): ?>

        <section class="dashboard-panel finance-panel">
            <div class="dashboard-panel-header">
                <div>
                    <span class="page-eyebrow">INICIO DEL DÍA</span>
                    <h2>Abrir caja</h2>
                </div>
            </div>
            <form class="cash-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="open">
                <div class="form-group">
                    <label class="form-label" for="opening_amount">Fondo inicial en efectivo (<?= e(currency_symbol()) ?>)</label>
                    <input class="form-input" type="text" inputmode="decimal" id="opening_amount" name="amount"
                        placeholder="0.00" required>
                </div>
                <button class="admin-button" type="submit">ABRIR CAJA</button>
            </form>
        </section>

    <?php else: ?>

        <?php render_cash_session_detail($pdo, $session); ?>

        <div class="cash-grid">
            <section class="dashboard-panel">
                <div class="dashboard-panel-header">
                    <div>
                        <span class="page-eyebrow">EFECTIVO</span>
                        <h2>Entrada o salida</h2>
                    </div>
                </div>
                <form class="cash-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="movement">
                    <div class="cash-form-row">
                        <div class="form-group">
                            <label class="form-label" for="type">Tipo</label>
                            <select class="form-select" id="type" name="type">
                                <?php foreach (cash_movement_types() as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $value === 'out' ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="movement_amount">Monto</label>
                            <input class="form-input" type="text" inputmode="decimal" id="movement_amount" name="amount"
                                placeholder="0.00" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="reason">Motivo</label>
                        <input class="form-input" type="text" id="reason" name="reason" maxlength="190"
                            placeholder="Ej. pago de delivery, cambio, retiro del dueño" required>
                    </div>
                    <button class="admin-button admin-button-outline" type="submit">REGISTRAR</button>
                </form>
            </section>

            <section class="dashboard-panel">
                <div class="dashboard-panel-header">
                    <div>
                        <span class="page-eyebrow">FIN DEL DÍA</span>
                        <h2>Cerrar caja</h2>
                    </div>
                </div>
                <form class="cash-form" method="post"
                    onsubmit="return confirm('¿Cerrar la caja? Después no se puede reabrir.');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="close">
                    <div class="form-group">
                        <label class="form-label" for="counted_amount">Efectivo contado (<?= e(currency_symbol()) ?>)</label>
                        <input class="form-input" type="text" inputmode="decimal" id="counted_amount" name="amount"
                            placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="notes">Notas</label>
                        <input class="form-input" type="text" id="notes" name="notes" placeholder="Opcional">
                    </div>
                    <button class="admin-button" type="submit">CERRAR CAJA</button>
                </form>
            </section>
        </div>

    <?php endif; ?>

    <section class="dashboard-panel finance-panel">
        <div class="dashboard-panel-header">
            <div>
                <span class="page-eyebrow">HISTORIAL</span>
                <h2>Cierres anteriores</h2>
            </div>
        </div>
        <?php if ($history === []): ?>
            <p class="cash-empty">Todavía no hay cierres de caja.</p>
        <?php else: ?>
            <div class="admin-table-wrapper">
                <table class="admin-table finance-table">
                    <thead>
                        <tr>
                            <th>Cierre</th>
                            <th>Cerró</th>
                            <th class="num">Esperado</th>
                            <th class="num">Contado</th>
                            <th class="num">Diferencia</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $closed): ?>
                            <?php $difference = round((float) $closed['counted_amount'] - (float) $closed['expected_amount'], 2); ?>
                            <tr>
                                <td><?= e(date('d/m/Y H:i', strtotime((string) $closed['closed_at']))) ?></td>
                                <td><?= e($closed['closed_by_name'] ?? '—') ?></td>
                                <td class="num"><?= e(format_money($closed['expected_amount'])) ?></td>
                                <td class="num"><?= e(format_money($closed['counted_amount'])) ?></td>
                                <td class="num <?= $difference < 0 ? 'cash-difference-short' : ($difference > 0 ? 'cash-difference-over' : '') ?>">
                                    <?= $difference > 0 ? '+' : ($difference < 0 ? '−' : '') ?><?= e(format_money(abs($difference))) ?>
                                </td>
                                <td><a href="<?= e(APP_BASE_URL) ?>/admin/cash/view.php?id=<?= (int) $closed['id'] ?>">Ver →</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php endif; ?>

<?php

render_sales_layout_end();
