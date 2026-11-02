<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/invoice-functions.php';

require_admin();


/*
|--------------------------------------------------------------------------
| Load Sale
|--------------------------------------------------------------------------
*/

$saleId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($saleId === false || !sales_tables_exist($pdo)) {
    header('Location: ' . APP_BASE_URL . '/admin/sales/');
    exit;
}

$saleStatement = $pdo->prepare(
    'SELECT s.*, a.name AS admin_name
     FROM sales s
     LEFT JOIN admins a ON a.id = s.admin_id
     WHERE s.id = :id
     LIMIT 1'
);
$saleStatement->execute(['id' => $saleId]);
$sale = $saleStatement->fetch();

if (!$sale) {
    header('Location: ' . APP_BASE_URL . '/admin/sales/');
    exit;
}

$itemsStatement = $pdo->prepare(
    'SELECT si.*, COALESCE(si.image_path, p.main_image) AS main_image
     FROM sale_items si
     LEFT JOIN products p ON p.id = si.product_id
     WHERE si.sale_id = :sale_id
     ORDER BY si.id ASC'
);
$itemsStatement->execute(['sale_id' => $saleId]);
$items = $itemsStatement->fetchAll();

$saleNumber = $sale['sale_number'] ?: sale_number_for_id((int) $sale['id']);
$docTitle = sale_doc_type_titles()[$sale['doc_type']] ?? 'FACTURA';
$isInvoice = $sale['doc_type'] === 'invoice';
$totals = invoice_totals($sale);
$isCancelled = $sale['status'] === 'cancelled';
$soldAt = strtotime((string) $sale['sold_at']);
$base = APP_BASE_URL;

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(mb_convert_case($docTitle, MB_CASE_TITLE)) ?> <?= e($saleNumber) ?> | <?= e(business_name()) ?></title>
    <link href="<?= e(business_favicon_url()) ?>" rel="icon" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --ink: #2b1d14;
            --brand: #4a2e1f;
            --muted: #7a6a5e;
            --line: #e6ddd4;
            --soft: #f7f2ec;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #ece6df;
            color: var(--ink);
            font-family: Inter, Arial, sans-serif;
            font-size: 13px;
        }

        .invoice-toolbar {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            padding: 18px 16px;
        }

        .invoice-toolbar a,
        .invoice-toolbar button {
            border: 1px solid var(--brand);
            background: #fff;
            color: var(--brand);
            padding: 10px 18px;
            font: 600 12px Inter, Arial, sans-serif;
            letter-spacing: .08em;
            text-decoration: none;
            cursor: pointer;
        }

        .invoice-toolbar button {
            background: var(--brand);
            color: #fff;
        }

        .invoice {
            position: relative;
            max-width: 800px;
            margin: 0 auto 40px;
            padding: 48px 52px;
            background: #fff;
            box-shadow: 0 10px 30px rgba(43, 29, 20, .08);
            overflow: hidden;
        }

        .invoice-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            padding-bottom: 24px;
            border-bottom: 2px solid var(--brand);
        }

        .invoice-logo {
            width: 190px;
            height: auto;
            display: block;
        }

        .invoice-business {
            margin-top: 10px;
            color: var(--muted);
            line-height: 1.6;
        }

        .invoice-title {
            text-align: right;
        }

        .invoice-title h1 {
            margin: 0;
            font: 600 34px "Cormorant Garamond", Georgia, serif;
            letter-spacing: .12em;
            color: var(--brand);
        }

        .invoice-title dl {
            display: grid;
            grid-template-columns: auto auto;
            gap: 4px 14px;
            margin: 10px 0 0;
            justify-content: end;
        }

        .invoice-title dt {
            color: var(--muted);
        }

        .invoice-title dd {
            margin: 0;
            font-weight: 600;
        }

        .invoice-parties {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin: 26px 0;
        }

        .invoice-box {
            background: var(--soft);
            padding: 14px 16px;
            line-height: 1.6;
        }

        .invoice-box h2 {
            margin: 0 0 6px;
            font-size: 10px;
            letter-spacing: .14em;
            color: var(--muted);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            font-size: 10px;
            letter-spacing: .12em;
            color: #fff;
            background: var(--brand);
            padding: 9px 10px;
        }

        td {
            padding: 10px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }

        .num {
            text-align: right;
            white-space: nowrap;
        }

        .item-photo {
            width: 56px;
            height: 56px;
            object-fit: cover;
            display: block;
            background: var(--soft);
        }

        .col-photo {
            width: 72px;
        }

        .item-meta {
            color: var(--muted);
            font-size: 12px;
        }

        .invoice-totals {
            width: 300px;
            margin: 20px 0 0 auto;
        }

        .invoice-totals td {
            padding: 7px 10px;
        }

        .invoice-totals .grand td {
            border-bottom: 0;
            border-top: 2px solid var(--brand);
            font-size: 16px;
            font-weight: 600;
            color: var(--brand);
        }

        .invoice-note {
            margin-top: 26px;
            color: var(--muted);
            line-height: 1.6;
        }

        .invoice-foot {
            margin-top: 34px;
            padding-top: 16px;
            border-top: 1px solid var(--line);
            text-align: center;
            font: 500 18px "Cormorant Garamond", Georgia, serif;
            color: var(--brand);
        }

        .invoice-void {
            position: absolute;
            top: 42%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-24deg);
            font: 600 110px "Cormorant Garamond", Georgia, serif;
            letter-spacing: .1em;
            color: rgba(176, 42, 42, .14);
            pointer-events: none;
        }

        @media (max-width: 640px) {
            .invoice {
                padding: 28px 18px;
            }

            .invoice-head,
            .invoice-parties {
                display: block;
            }

            .invoice-title {
                text-align: left;
                margin-top: 18px;
            }

            .invoice-title dl {
                justify-content: start;
            }

            .invoice-box + .invoice-box {
                margin-top: 12px;
            }

            .invoice-totals {
                width: 100%;
            }

            .col-code {
                display: none;
            }
        }

        @page {
            size: letter;
            margin: 12mm;
        }

        @media print {
            body {
                background: #fff;
            }

            .invoice-toolbar {
                display: none;
            }

            .invoice {
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            th,
            .invoice-box {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>

<body>

    <div class="invoice-toolbar">
        <a href="<?= e($base) ?>/admin/sales/view.php?id=<?= (int) $sale['id'] ?>">← VOLVER A LA VENTA</a>
        <button type="button" onclick="window.print()">IMPRIMIR / GUARDAR PDF</button>
    </div>

    <main class="invoice">

        <?php if ($isCancelled): ?>
            <div class="invoice-void">ANULADA</div>
        <?php endif; ?>

        <header class="invoice-head">
            <div>
                <img class="invoice-logo" src="<?= e(business_logo_url()) ?>" alt="<?= e(business_name()) ?>">
                <div class="invoice-business">
                    <?php if (INVOICE_BUSINESS_TAX_ID !== ''): ?>
                        RUC: <?= e(INVOICE_BUSINESS_TAX_ID) ?><br>
                    <?php endif; ?>
                    <?php if (INVOICE_BUSINESS_ADDRESS !== ''): ?>
                        <?= e(INVOICE_BUSINESS_ADDRESS) ?><br>
                    <?php endif; ?>
                    <?php if (INVOICE_BUSINESS_PHONE !== ''): ?>
                        Tel. / WhatsApp: <?= e(INVOICE_BUSINESS_PHONE) ?><br>
                    <?php endif; ?>
                    <?php if (INVOICE_BUSINESS_INSTAGRAM !== ''): ?>
                        Instagram: <?= e(INVOICE_BUSINESS_INSTAGRAM) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="invoice-title">
                <h1><?= e($docTitle) ?></h1>
                <dl>
                    <dt>N.º</dt>
                    <dd><?= e($saleNumber) ?></dd>
                    <dt>Fecha</dt>
                    <dd><?= e(date('d/m/Y', $soldAt)) ?></dd>
                    <dt>Hora</dt>
                    <dd><?= e(date('H:i', $soldAt)) ?></dd>
                </dl>
            </div>
        </header>

        <section class="invoice-parties">
            <div class="invoice-box">
                <h2>CLIENTE</h2>
                <?= e($sale['customer_name'] ?: 'Cliente de contado') ?><br>
                <?php if ((string) $sale['customer_phone'] !== ''): ?>
                    Tel.: <?= e($sale['customer_phone']) ?>
                <?php endif; ?>
            </div>

            <div class="invoice-box">
                <h2>DETALLES DEL PAGO</h2>
                Forma de pago: <?= e(sale_option_label(sale_payment_methods(), $sale['payment_method'])) ?><br>
                Canal: <?= e(sale_option_label(sale_channels(), $sale['channel'])) ?>
                <?php if ($isInvoice): ?>
                    <br>Estado: <?= e(sale_option_label(sale_statuses(), $sale['status'])) ?>
                <?php endif; ?>
            </div>
        </section>

        <table>
            <thead>
                <tr>
                    <th class="col-photo"></th>
                    <th class="col-code">CÓDIGO</th>
                    <th>DESCRIPCIÓN</th>
                    <th class="num">CANT.</th>
                    <th class="num">P. UNIT.</th>
                    <th class="num">IMPORTE</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $details = array_filter([
                        (string) $item['color_name'],
                        (string) $item['size'] !== '' ? 'Talla ' . $item['size'] : '',
                    ]);
                    ?>
                    <tr>
                        <td class="col-photo">
                            <?php if ((string) ($item['main_image'] ?? '') !== ''): ?>
                                <img class="item-photo" src="<?= e($base . '/' . ltrim((string) $item['main_image'], '/')) ?>"
                                    alt="<?= e($item['product_name']) ?>">
                            <?php endif; ?>
                        </td>
                        <td class="col-code"><?= e($item['product_code']) ?></td>
                        <td>
                            <?= e($item['product_name']) ?>
                            <?php if ($details): ?>
                                <div class="item-meta"><?= e(implode(' · ', $details)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= (int) $item['quantity'] ?></td>
                        <td class="num"><?= e(format_money($item['unit_price'])) ?></td>
                        <td class="num"><?= e(format_money($item['line_total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <table class="invoice-totals">
            <tr>
                <td>Subtotal</td>
                <td class="num"><?= e(format_money($totals['subtotal'])) ?></td>
            </tr>
            <?php if ($totals['discount'] > 0): ?>
                <tr>
                    <td>
                        Descuento
                        <?php if (!empty($sale['promotion_name'])): ?>
                            <br><small>Promoción: <?= e($sale['promotion_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="num">-<?= e(format_money($totals['discount'])) ?></td>
                </tr>
            <?php endif; ?>
            <?php if (INVOICE_SHOW_TAX): ?>
            <tr>
                <td>Base imponible</td>
                <td class="num"><?= e(format_money($totals['base'])) ?></td>
            </tr>
            <tr>
                <td><?= e(invoice_tax_label()) ?></td>
                <td class="num"><?= e(format_money($totals['tax'])) ?></td>
            </tr>
            <?php endif; ?>
            <tr class="grand">
                <td>TOTAL</td>
                <td class="num"><?= e(format_money($totals['total'])) ?></td>
            </tr>
        </table>

        <div class="invoice-note">
            <?php if (INVOICE_SHOW_TAX && INVOICE_PRICES_INCLUDE_TAX): ?>
                Precios con <?= e(INVOICE_TAX_LABEL) ?> incluido.
            <?php endif; ?>
            <?php if ((string) $sale['notes'] !== ''): ?>
                <br>Notas: <?= nl2br(e($sale['notes'])) ?>
            <?php endif; ?>
        </div>

        <footer class="invoice-foot">
            <?= $isInvoice ? '¡Gracias por tu compra!' : 'Precios sujetos a disponibilidad. ¡Gracias por preferirnos!' ?>
        </footer>

    </main>

    <?php if (isset($_GET['print'])): ?>
        <script>window.addEventListener('load', () => window.print());</script>
    <?php endif; ?>

</body>

</html>
