<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Cash Session Detail
|--------------------------------------------------------------------------
|
| Summary, payments and cash in/out of one session. Used by the open
| register (index.php) and by the printable arqueo (view.php).
|
*/

function render_cash_session_detail(PDO $pdo, array $session): void
{
    $summary = cash_session_summary($pdo, $session);
    $payments = cash_session_payments($pdo, (int) $session['id']);
    $movements = cash_session_movements($pdo, (int) $session['id']);
    $methods = sale_payment_methods();
    $isClosed = $session['closed_at'] !== null;
    $difference = $isClosed ? round((float) $session['counted_amount'] - (float) $session['expected_amount'], 2) : 0.0;
    ?>
    <div class="cash-grid">
        <section class="dashboard-panel">
            <div class="dashboard-panel-header">
                <div>
                    <span class="page-eyebrow">EFECTIVO</span>
                    <h2><?= $isClosed ? 'Arqueo' : 'Lo que debe haber en caja' ?></h2>
                </div>
            </div>
            <dl class="cash-summary">
                <div><dt>Fondo inicial</dt><dd><?= e(format_money($summary['opening'])) ?></dd></div>
                <div><dt>+ Ventas en efectivo</dt><dd><?= e(format_money($summary['by_method']['cash'] ?? 0)) ?></dd></div>
                <div><dt>+ Entradas</dt><dd><?= e(format_money($summary['cash_in'])) ?></dd></div>
                <div><dt>− Salidas</dt><dd><?= e(format_money($summary['cash_out'])) ?></dd></div>
                <div class="cash-summary-total">
                    <dt>Efectivo esperado</dt>
                    <dd><?= e(format_money($isClosed ? $session['expected_amount'] : $summary['expected_cash'])) ?></dd>
                </div>
                <?php if ($isClosed): ?>
                    <div><dt>Efectivo contado</dt><dd><?= e(format_money($session['counted_amount'])) ?></dd></div>
                    <div class="cash-summary-total">
                        <dt>Diferencia</dt>
                        <dd class="<?= $difference < 0 ? 'cash-difference-short' : ($difference > 0 ? 'cash-difference-over' : '') ?>">
                            <?= $difference < 0 ? 'Falta ' : ($difference > 0 ? 'Sobra ' : 'Cuadra · ') ?><?= e(format_money(abs($difference))) ?>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
        </section>

        <section class="dashboard-panel">
            <div class="dashboard-panel-header">
                <div>
                    <span class="page-eyebrow">COBRADO</span>
                    <h2>Por método de pago</h2>
                </div>
            </div>
            <dl class="cash-summary">
                <?php foreach ($methods as $key => $label): ?>
                    <div><dt><?= e($label) ?></dt><dd><?= e(format_money($summary['by_method'][$key] ?? 0)) ?></dd></div>
                <?php endforeach; ?>
                <div class="cash-summary-total"><dt>Total cobrado</dt><dd><?= e(format_money($summary['sales_total'])) ?></dd></div>
            </dl>
            <p class="cash-empty">
                Abierta <?= e(date('d/m/Y H:i', strtotime((string) $session['opened_at']))) ?>
                por <?= e($session['opened_by_name'] ?? '—') ?>
                <?php if ($isClosed): ?>
                    · cerrada <?= e(date('d/m/Y H:i', strtotime((string) $session['closed_at']))) ?>
                    por <?= e($session['closed_by_name'] ?? '—') ?>
                <?php endif; ?>
                <?php if (!empty($session['notes'])): ?>
                    <br>Notas: <?= e($session['notes']) ?>
                <?php endif; ?>
            </p>
        </section>
    </div>

    <section class="dashboard-panel finance-panel">
        <div class="dashboard-panel-header">
            <div>
                <span class="page-eyebrow">DETALLE</span>
                <h2>Pagos recibidos</h2>
            </div>
        </div>
        <?php if ($payments === []): ?>
            <p class="cash-empty">Todavía no hay pagos en esta caja.</p>
        <?php else: ?>
            <div class="admin-table-wrapper">
                <table class="admin-table finance-table">
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Venta</th>
                            <th>Método</th>
                            <th>Usuario</th>
                            <th class="num">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= e(date('H:i', strtotime((string) $payment['paid_at']))) ?></td>
                                <td>
                                    <a href="<?= e(APP_BASE_URL) ?>/admin/sales/view.php?id=<?= (int) $payment['sale_id'] ?>">
                                        <?= e($payment['sale_number'] ?: '#' . $payment['sale_id']) ?>
                                    </a>
                                    <?= $payment['customer_name'] ? '· ' . e($payment['customer_name']) : '' ?>
                                    <?= $payment['sale_status'] === 'cancelled' ? '<strong>(anulada, no cuenta)</strong>' : '' ?>
                                </td>
                                <td><?= e(sale_option_label($methods, $payment['method'])) ?></td>
                                <td><?= e($payment['admin_name'] ?? '—') ?></td>
                                <td class="num"><?= e(format_money($payment['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="dashboard-panel finance-panel">
        <div class="dashboard-panel-header">
            <div>
                <span class="page-eyebrow">DETALLE</span>
                <h2>Entradas y salidas de efectivo</h2>
            </div>
        </div>
        <?php if ($movements === []): ?>
            <p class="cash-empty">Sin entradas ni salidas.</p>
        <?php else: ?>
            <div class="admin-table-wrapper">
                <table class="admin-table finance-table">
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Tipo</th>
                            <th>Motivo</th>
                            <th>Usuario</th>
                            <th class="num">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $movement): ?>
                            <tr>
                                <td><?= e(date('H:i', strtotime((string) $movement['created_at']))) ?></td>
                                <td><?= e(cash_movement_types()[$movement['type']] ?? $movement['type']) ?></td>
                                <td><?= e($movement['reason']) ?></td>
                                <td><?= e($movement['admin_name'] ?? '—') ?></td>
                                <td class="num"><?= $movement['type'] === 'out' ? '−' : '' ?><?= e(format_money($movement['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php
}
