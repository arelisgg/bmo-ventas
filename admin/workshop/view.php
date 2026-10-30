<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/invoice-functions.php';
require_once __DIR__ . '/../../includes/workshop-functions.php';

require_admin();

$orderId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($orderId === false || !workshop_tables_exist($pdo)) {
    header('Location: ' . APP_BASE_URL . '/admin/workshop/');
    exit;
}

$statuses = workshop_statuses();


/*
|--------------------------------------------------------------------------
| Status / Delete
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'status' && isset($statuses[(string) ($_POST['status'] ?? '')])) {
        $pdo->prepare('UPDATE workshop_orders SET status = :status WHERE id = :id')
            ->execute(['status' => (string) $_POST['status'], 'id' => $orderId]);

        header('Location: ' . APP_BASE_URL . '/admin/workshop/view.php?id=' . $orderId . '&updated=1');
        exit;
    }

    if ($action === 'delete') {
        $images = $pdo->prepare('SELECT image_path FROM workshop_order_models WHERE order_id = :id');
        $images->execute(['id' => $orderId]);
        $images = $images->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare('DELETE FROM workshop_orders WHERE id = :id')->execute(['id' => $orderId]);

        foreach ($images as $imagePath) {
            delete_workshop_model_image($imagePath);
        }

        header('Location: ' . APP_BASE_URL . '/admin/workshop/?deleted=1');
        exit;
    }
}

$order = workshop_load_order($pdo, $orderId);

if ($order === null) {
    header('Location: ' . APP_BASE_URL . '/admin/workshop/');
    exit;
}

$orderNumber = workshop_order_number((int) $order['id']);
$isCancelled = $order['status'] === 'cancelled';
$base = APP_BASE_URL;

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido al taller <?= e($orderNumber) ?> | <?= e(business_name()) ?></title>
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

        .doc-toolbar {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 18px 16px;
        }

        .doc-toolbar a,
        .doc-toolbar button,
        .doc-toolbar select {
            border: 1px solid var(--brand);
            background: #fff;
            color: var(--brand);
            padding: 10px 16px;
            font: 600 12px Inter, Arial, sans-serif;
            letter-spacing: .06em;
            text-decoration: none;
            cursor: pointer;
        }

        .doc-toolbar form {
            display: flex;
            gap: 6px;
            margin: 0;
        }

        .doc-toolbar .primary {
            background: var(--brand);
            color: #fff;
        }

        .doc-toolbar .danger {
            border-color: #8c4737;
            color: #8c4737;
        }

        .doc-notice {
            max-width: 800px;
            margin: 0 auto 14px;
            padding: 12px 16px;
            background: #edf5eb;
            color: #31562d;
            text-align: center;
        }

        .doc {
            position: relative;
            max-width: 800px;
            margin: 0 auto 40px;
            padding: 44px 48px;
            background: #fff;
            box-shadow: 0 10px 30px rgba(43, 29, 20, .08);
            overflow: hidden;
        }

        .doc-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--brand);
        }

        .doc-logo {
            width: 170px;
            height: auto;
            display: block;
        }

        .doc-business {
            margin-top: 8px;
            color: var(--muted);
            line-height: 1.6;
        }

        .doc-title {
            text-align: right;
        }

        .doc-title h1 {
            margin: 0;
            font: 600 30px "Cormorant Garamond", Georgia, serif;
            letter-spacing: .1em;
            color: var(--brand);
        }

        .doc-title dl {
            display: grid;
            grid-template-columns: auto auto;
            gap: 4px 14px;
            margin: 10px 0 0;
            justify-content: end;
        }

        .doc-title dt {
            color: var(--muted);
        }

        .doc-title dd {
            margin: 0;
            font-weight: 600;
        }

        .doc-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 28px;
            margin: 20px 0;
            padding: 14px 16px;
            background: var(--soft);
            line-height: 1.6;
        }

        .doc-summary strong {
            display: block;
            font-size: 10px;
            letter-spacing: .14em;
            color: var(--muted);
        }

        .doc-summary .wide {
            flex-basis: 100%;
        }

        .model {
            display: grid;
            grid-template-columns: 300px minmax(0, 1fr);
            gap: 26px;
            padding: 24px 0;
            border-bottom: 1px solid var(--line);
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .model-photo {
            width: 100%;
            height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--soft);
            border: 1px solid var(--line);
        }

        .model-photo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .model-photo span {
            color: var(--muted);
        }

        .model-index {
            font-size: 10px;
            letter-spacing: .14em;
            color: var(--muted);
        }

        .model h2 {
            margin: 4px 0 2px;
            font: 600 26px "Cormorant Garamond", Georgia, serif;
            color: var(--brand);
        }

        .model-code {
            color: var(--muted);
        }

        .model-notes {
            margin: 0;
            font-size: 14px;
            padding: 10px 12px;
            border-left: 3px solid var(--brand);
            background: var(--soft);
            line-height: 1.5;
        }

        .model-label {
            display: block;
            margin: 18px 0 6px;
            font-size: 10px;
            letter-spacing: .14em;
            color: var(--muted);
        }

        .model-colors {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .model-colors li {
            padding: 9px 0;
            border-bottom: 1px solid var(--line);
            font-size: 15px;
            line-height: 1.6;
        }

        .model-colors li strong {
            margin-right: 6px;
        }

        .model-colors .pairs {
            color: var(--muted);
            white-space: nowrap;
        }

        .model-total {
            margin-top: 10px;
            font-weight: 600;
        }

        .doc-total {
            margin-top: 22px;
            text-align: right;
            font: 600 22px "Cormorant Garamond", Georgia, serif;
            color: var(--brand);
        }

        .doc-void {
            position: absolute;
            top: 30%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-24deg);
            font: 600 110px "Cormorant Garamond", Georgia, serif;
            letter-spacing: .1em;
            color: rgba(176, 42, 42, .14);
            pointer-events: none;
        }

        @media (max-width: 640px) {
            .doc {
                padding: 26px 16px;
            }

            .doc-head {
                display: block;
            }

            .doc-title {
                text-align: left;
                margin-top: 16px;
            }

            .doc-title dl {
                justify-content: start;
            }

            .model {
                grid-template-columns: minmax(0, 1fr);
                gap: 14px;
            }

            .model-photo {
                height: 260px;
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

            .doc-toolbar,
            .doc-notice {
                display: none;
            }

            .doc {
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            .doc-summary,
            .model-photo,
            .model-notes {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>

<body>

    <div class="doc-toolbar">
        <a href="<?= e($base) ?>/admin/workshop/">← PEDIDOS</a>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="status">
            <select name="status" aria-label="Estado del pedido" onchange="this.form.submit()">
                <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $order['status'] === $value ? 'selected' : '' ?>>
                        Estado: <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <button class="primary" type="button" onclick="window.print()">IMPRIMIR / GUARDAR PDF</button>
        <form method="post" onsubmit="return confirm('¿Eliminar este pedido? No se puede deshacer.');">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <button class="danger" type="submit">ELIMINAR</button>
        </form>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="doc-notice">Pedido guardado. Imprímelo o guárdalo en PDF para enviarlo al taller.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="doc-notice">Estado actualizado.</div>
    <?php endif; ?>

    <main class="doc">

        <?php if ($isCancelled): ?>
            <div class="doc-void">ANULADO</div>
        <?php endif; ?>

        <header class="doc-head">
            <div>
                <img class="doc-logo" src="<?= e(business_logo_url()) ?>" alt="<?= e(business_name()) ?>">
                <div class="doc-business">
                    Tel. / WhatsApp: <?= e(INVOICE_BUSINESS_PHONE) ?>
                </div>
            </div>

            <div class="doc-title">
                <h1>PEDIDO AL TALLER</h1>
                <dl>
                    <dt>N.º</dt>
                    <dd><?= e($orderNumber) ?></dd>
                    <dt>Fecha</dt>
                    <dd><?= e(date('d/m/Y', strtotime((string) $order['created_at']))) ?></dd>
                    <?php if ((string) $order['due_date'] !== ''): ?>
                        <dt>Entrega</dt>
                        <dd><?= e(date('d/m/Y', strtotime((string) $order['due_date']))) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </header>

        <section class="doc-summary">
            <?php if ((string) $order['reference'] !== ''): ?>
                <div><strong>CLIENTE / REFERENCIA</strong><?= e($order['reference']) ?></div>
            <?php endif; ?>
            <?php if ((string) $order['sale_number'] !== ''): ?>
                <div><strong>VENTA</strong><?= e($order['sale_number']) ?></div>
            <?php endif; ?>
            <div><strong>MODELOS</strong><?= count($order['models']) ?></div>
            <div><strong>TOTAL DE PARES</strong><?= (int) $order['total_pairs'] ?></div>
            <?php if ((string) $order['notes'] !== ''): ?>
                <div class="wide"><strong>NOTAS</strong><?= nl2br(e($order['notes'])) ?></div>
            <?php endif; ?>
        </section>

        <?php foreach ($order['models'] as $position => $model): ?>
            <?php $matrix = workshop_line_matrix($model['lines']); ?>
            <article class="model">
                <div class="model-photo">
                    <?php if ((string) $model['photo'] !== ''): ?>
                        <img src="<?= e($base . '/' . ltrim((string) $model['photo'], '/')) ?>"
                            alt="<?= e($model['product_name']) ?>">
                    <?php else: ?>
                        <span>Sin foto</span>
                    <?php endif; ?>
                </div>

                <div>
                    <span class="model-index">MODELO <?= $position + 1 ?></span>
                    <h2><?= e($model['product_name']) ?></h2>
                    <?php if ((string) $model['product_code'] !== ''): ?>
                        <div class="model-code">Código: <?= e($model['product_code']) ?></div>
                    <?php endif; ?>

                    <span class="model-label">COLORES, TALLAS Y CANTIDADES</span>
                    <ul class="model-colors">
                        <?php foreach ($matrix['rows'] as $color => $quantities): ?>
                            <?php
                            $sizeParts = [];
                            foreach ($matrix['sizes'] as $size) {
                                if ((int) ($quantities[$size] ?? 0) > 0) {
                                    $sizeParts[] = ($size === 'Sin talla' ? 'sin talla' : 'talla ' . $size) . ' × ' . (int) $quantities[$size];
                                }
                            }
                            ?>
                            <li>
                                <strong><?= (string) $color !== '' ? e((string) $color) : 'Sin color' ?>:</strong>
                                <?= e(implode(', ', $sizeParts)) ?>
                                <span class="pairs">(<?= e(workshop_pairs_label(array_sum($quantities))) ?>)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="model-total">Total del modelo: <?= e(workshop_pairs_label((int) $model['pairs'])) ?></div>

                    <?php if ((string) $model['notes'] !== ''): ?>
                        <span class="model-label">DETALLES</span>
                        <p class="model-notes"><?= nl2br(e($model['notes'])) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>

        <div class="doc-total">Total: <?= e(workshop_pairs_label((int) $order['total_pairs'])) ?></div>

    </main>

    <?php if (isset($_GET['print'])): ?>
        <script>window.addEventListener('load', () => window.print());</script>
    <?php endif; ?>

</body>

</html>
