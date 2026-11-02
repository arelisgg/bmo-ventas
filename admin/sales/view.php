<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/payment-functions.php';
require_once __DIR__ . '/_layout.php';

require_admin();


/*
|--------------------------------------------------------------------------
| Sale ID
|--------------------------------------------------------------------------
*/

$saleId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);


if (
    $saleId === false ||
    !sales_tables_exist($pdo)
) {

    header(
        'Location: ' .
        APP_BASE_URL .
        '/admin/sales/'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Load Sale
|--------------------------------------------------------------------------
*/

$saleStatement = $pdo->prepare(
    'SELECT
        s.*,
        a.name AS admin_name

     FROM sales s

     LEFT JOIN admins a
        ON a.id = s.admin_id

     WHERE s.id = :id

     LIMIT 1'
);


$saleStatement->execute([
    'id' => $saleId,
]);


$sale =
    $saleStatement->fetch();


if (!$sale) {

    header(
        'Location: ' .
        APP_BASE_URL .
        '/admin/sales/'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Load Sale Items
|--------------------------------------------------------------------------
*/

$itemsStatement = $pdo->prepare(
    'SELECT
        si.*,
        COALESCE(si.image_path, p.main_image) AS main_image

     FROM sale_items si

     LEFT JOIN products p
        ON p.id = si.product_id

     WHERE si.sale_id = :sale_id

     ORDER BY si.id ASC'
);


$itemsStatement->execute([
    'sale_id' => $saleId,
]);


$items =
    $itemsStatement->fetchAll();


$docTypeLabel = sale_option_label(sale_doc_types(), $sale['doc_type']);
$isInvoice = $sale['doc_type'] === 'invoice';

$saleNumber =
    $sale['sale_number']
    ?: sale_number_for_id(
        (int) $sale['id']
    );


/*
|--------------------------------------------------------------------------
| Labels
|--------------------------------------------------------------------------
*/

$statusLabels =
    sale_statuses();

$channelLabels =
    sale_channels();

$paymentLabels =
    sale_payment_methods();


/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
*/

$paymentsReady = payment_tables_exist($pdo) && $sale['doc_type'] === 'invoice';
$payments = $paymentsReady ? sale_payments($pdo, (int) $sale['id']) : [];
$paidAmount = round(array_sum(array_map(static fn (array $payment): float => (float) $payment['amount'], $payments)), 2);
$balance = round((float) $sale['total'] - $paidAmount, 2);
$paymentMethodsUsed = array_unique(array_column($payments, 'method'));
$canAddPayment = $paymentsReady && $sale['status'] !== 'cancelled' && $balance > 0;


/*
|--------------------------------------------------------------------------
| WhatsApp Receipt
|--------------------------------------------------------------------------
*/

$receiptLines = [
    business_name() . ' · Comprobante ' .
    $saleNumber,

    'Fecha: ' .
    date(
        'd/m/Y',
        strtotime(
            (string) 
            $sale['sold_at']
        )
    ),

    '',
];


foreach (
    $items
    as
    $item
) {

    $itemDetails = array_filter([
        (string) $item['color_name'],
        (string) $item['size'] !== ''
            ? 'talla ' . $item['size']
            : '',
    ]);

    $receiptLines[] =
        sprintf(
            '%d x %s%s - %s',
            (int) $item['quantity'],
            $item['product_name'],
            count($itemDetails) > 0
                ? ' (' . implode(', ', $itemDetails) . ')'
                : '',
            format_money(
                $item['line_total']
            )
        );
}


if (
    (float) 
    $sale['discount']
    > 0
) {

    $receiptLines[] =
        'Descuento: -' .
        format_money(
            $sale['discount']
        );
}


$receiptLines[] =
    'Total: ' .
    format_money(
        $sale['total']
    );


$receiptLines[] =
    'Estado: ' .
    sale_option_label(
        $statusLabels,
        $sale['status']
    );


$receiptLines[] = '';

$receiptLines[] =
    '¡Gracias por tu compra!';


/*
|--------------------------------------------------------------------------
| WhatsApp URL
|--------------------------------------------------------------------------
*/

$whatsappUrl = null;


$phoneDigits =
    preg_replace(
        '/\D+/',
        '',
        (string) 
        $sale['customer_phone']
    ) ?? '';


if (
    $phoneDigits !== ''
) {

    /*
    |--------------------------------------------------------------------------
    | Nicaragua Number
    |--------------------------------------------------------------------------
    */

    if (
        strlen(
            $phoneDigits
        ) === 8
    ) {

        $phoneDigits =
            '505' .
            $phoneDigits;
    }


    $whatsappUrl =
        'https://wa.me/' .
        $phoneDigits .
        '?text=' .
        rawurlencode(
            implode(
                "\n",
                $receiptLines
            )
        );
}


/*
|--------------------------------------------------------------------------
| Layout
|--------------------------------------------------------------------------
*/

render_sales_layout_start(
    'Venta ' .
    $saleNumber
);

?>


<!-- =========================================================
     PAGE HEADER
     ========================================================= -->

<div class="page-header sale-view-header">

    <div>

        <span class="page-eyebrow">
            <?= e(mb_strtoupper($docTypeLabel)) ?>
        </span>

        <div class="sale-view-title">

            <h1 class="page-title">
                <?= e(
                    $saleNumber
                ) ?>
            </h1>

            <span class="
                    status-badge
                    sale-status-<?= e(
                        $sale['status']
                    ) ?>
                ">
                <?= e(
                    sale_option_label(
                        $statusLabels,
                        $sale['status']
                    )
                ) ?>
            </span>

        </div>


        <p class="page-description">

            <?= e(
                date(
                    'd/m/Y H:i',
                    strtotime(
                        (string) 
                        $sale['sold_at']
                    )
                )
            ) ?>

            · Registrada por

            <?= e(
                $sale['admin_name']
                ?? '—'
            ) ?>

        </p>

    </div>


    <a class="
            admin-button
            admin-button-outline
        " href="<?= e(
            APP_BASE_URL
        ) ?>/admin/sales/">
        ← VOLVER A VENTAS
    </a>

</div>



<!-- =========================================================
     NOTICES
     ========================================================= -->

<?php if (
    isset(
    $_GET['created']
)
): ?>

    <div class="
            admin-notice
            admin-notice-success
        ">
        <?= $isInvoice ? 'Venta registrada. El stock ya fue descontado.' : e($docTypeLabel) . ' guardada. No descuenta stock.' ?>
        <a href="<?= e(
            APP_BASE_URL
        ) ?>/admin/sales/invoice.php?id=<?= (int) $sale['id'] ?>" target="_blank" rel="noopener">
            Ver documento
        </a>
    </div>

<?php endif; ?>


<?php if (isset($_GET['updated'])): ?>
    <div class="admin-notice admin-notice-success">
        Cambios guardados.
    </div>
<?php endif; ?>

<?php if (isset($_GET['converted'])): ?>
    <div class="admin-notice admin-notice-success">
        Convertida en factura de venta. El stock ya fue descontado.
    </div>
<?php endif; ?>

<?php if (isset($_GET['payment'])): ?>
    <div class="admin-notice admin-notice-success">
        Pago registrado.
    </div>
<?php endif; ?>

<?php if (isset($_GET['payment_deleted'])): ?>
    <div class="admin-notice admin-notice-success">
        Pago eliminado.
    </div>
<?php endif; ?>

<?php if (isset($_GET['payment_error'])): ?>
    <div class="admin-notice admin-notice-error">
        <?= e((string) $_GET['payment_error']) ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['nostock'])): ?>
    <div class="admin-notice admin-notice-error">
        No hay stock suficiente para convertirla en factura. Edítala y ajusta los productos.
    </div>
<?php endif; ?>

<?php if (
    isset(
    $_GET['paid']
)
): ?>

    <div class="
            admin-notice
            admin-notice-success
        ">
        Venta marcada como pagada.
    </div>

<?php endif; ?>


<?php if (
    isset(
    $_GET['error']
)
): ?>

    <div class="
            admin-notice
            admin-notice-error
        ">
        No se pudo actualizar la venta.
    </div>

<?php endif; ?>



<!-- =========================================================
     SALE DETAIL
     ========================================================= -->

<div class="sale-detail-grid">


    <!-- =====================================================
         PRODUCTS
         ===================================================== -->

    <section class="dashboard-panel sale-view-products">

        <div class="dashboard-panel-header">

            <div>

                <span class="page-eyebrow">
                    PRODUCTOS
                </span>

                <h2>
                    Detalle de la compra
                </h2>

            </div>


            <span class="sale-view-item-count">

                <?= count(
                    $items
                ) ?>

                <?= count(
                    $items
                ) === 1
                    ? 'producto'
                    : 'productos'
                    ?>

            </span>

        </div>



        <div class="sale-view-product-list">


            <?php foreach (
                $items
                as
                $item
            ): ?>


                <article class="sale-view-product">


                    <!-- Image -->

                    <div class="sale-view-product-image">

                        <?php if (
                            !empty(
                            $item[
                                'main_image'
                            ]
                        )
                        ): ?>

                            <img src="<?= e(
                                APP_BASE_URL .
                                '/' .
                                ltrim(
                                    (string) 
                                    $item[
                                        'main_image'
                                    ],
                                    '/'
                                )
                            ) ?>" alt="<?= e(
                                 $item[
                                     'product_name'
                                 ]
                             ) ?>">

                        <?php else: ?>

                            <span>
                                Sin imagen
                            </span>

                        <?php endif; ?>

                    </div>



                    <!-- Info -->

                    <div class="sale-view-product-info">

                        <span class="product-code">

                            <?= e(
                                $item[
                                    'product_code'
                                ]
                            ) ?>

                        </span>


                        <strong>

                            <?= e(
                                $item[
                                    'product_name'
                                ]
                            ) ?>

                        </strong>


                        <div class="sale-view-product-meta">

                            <?php if (($item['source'] ?? 'inventory') === 'workshop'): ?>
                                <span class="sale-source-badge">
                                    Por encargo
                                </span>
                                <?php if (!empty($item['image_path'])): ?>
                                    <a class="sale-photo-link" href="<?= e(APP_BASE_URL . '/' . ltrim((string) $item['image_path'], '/')) ?>"
                                        target="_blank" rel="noopener noreferrer">
                                        Ver foto
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ((string) $item['color_name'] !== ''): ?>
                            <span>

                                <?= e(
                                    $item[
                                        'color_name'
                                    ]
                                ) ?>

                            </span>
                            <?php endif; ?>


                            <?php if ((string) $item['size'] !== ''): ?>
                            <span>
                                Talla
                                <?= e(
                                    $item[
                                        'size'
                                    ]
                                ) ?>
                            </span>
                            <?php endif; ?>

                        </div>

                    </div>



                    <!-- Quantity -->

                    <div class="sale-view-product-quantity">

                        <span>
                            CANT.
                        </span>

                        <strong>
                            <?= (int) 
                                $item[
                                    'quantity'
                                ]
                                ?>
                        </strong>

                    </div>



                    <!-- Price -->

                    <div class="sale-view-product-price">

                        <span>
                            PRECIO
                        </span>

                        <strong>

                            <?= e(
                                format_money(
                                    $item[
                                        'unit_price'
                                    ]
                                )
                            ) ?>

                        </strong>

                    </div>



                    <!-- Total -->

                    <div class="sale-view-product-total">

                        <span>
                            TOTAL
                        </span>

                        <strong>

                            <?= e(
                                format_money(
                                    $item[
                                        'line_total'
                                    ]
                                )
                            ) ?>

                        </strong>

                    </div>


                </article>


            <?php endforeach; ?>


        </div>



        <!-- =================================================
             TOTALS
             ================================================= -->

        <div class="sale-view-summary">

            <dl class="sale-totals">

                <div>

                    <dt>
                        Subtotal
                    </dt>

                    <dd>

                        <?= e(
                            format_money(
                                $sale[
                                    'subtotal'
                                ]
                            )
                        ) ?>

                    </dd>

                </div>


                <?php if (
                    (float) 
                    $sale['discount']
                    > 0
                ): ?>

                    <div>

                        <dt>
                            Descuento
                            <?php if (!empty($sale['promotion_name'])): ?>
                                <small class="sale-promotion-note">
                                    Incluye promoción "<?= e($sale['promotion_name']) ?>":
                                    -<?= e(format_money($sale['promotion_discount'])) ?>
                                </small>
                            <?php endif; ?>
                        </dt>

                        <dd class="sale-discount">

                            -<?= e(
                                format_money(
                                    $sale[
                                        'discount'
                                    ]
                                )
                            ) ?>

                        </dd>

                    </div>

                <?php endif; ?>


                <div class="sale-totals-grand">

                    <dt>
                        Total
                    </dt>

                    <dd>

                        <?= e(
                            format_money(
                                $sale[
                                    'total'
                                ]
                            )
                        ) ?>

                    </dd>

                </div>

            </dl>

        </div>

    </section>



    <!-- =====================================================
         DETAILS
         ===================================================== -->

    <aside class="dashboard-panel sale-view-sidebar">

        <div class="dashboard-panel-header">

            <div>

                <span class="page-eyebrow">
                    INFORMACIÓN
                </span>

                <h2>
                    Detalle de venta
                </h2>

            </div>

        </div>



        <!-- =================================================
             CUSTOMER
             ================================================= -->

        <div class="sale-view-meta-section">

            <span class="sale-view-meta-title">
                CLIENTE
            </span>


            <dl class="sale-meta">

                <div>

                    <dt>
                        Nombre
                    </dt>

                    <dd>

                        <?= e(
                            $sale[
                                'customer_name'
                            ]
                            ?: '—'
                        ) ?>

                    </dd>

                </div>


                <div>

                    <dt>
                        Teléfono
                    </dt>

                    <dd>

                        <?= e(
                            $sale[
                                'customer_phone'
                            ]
                            ?: '—'
                        ) ?>

                    </dd>

                </div>

            </dl>

        </div>



        <!-- =================================================
             PAYMENT
             ================================================= -->

        <div class="sale-view-meta-section">

            <span class="sale-view-meta-title">
                VENTA
            </span>


            <dl class="sale-meta">

                <div>

                    <dt>
                        Canal
                    </dt>

                    <dd>

                        <?= e(
                            sale_option_label(
                                $channelLabels,
                                $sale['channel']
                            )
                        ) ?>

                    </dd>

                </div>


                <div>

                    <dt>
                        Método de pago
                    </dt>

                    <dd>

                        <?= e(
                            count($paymentMethodsUsed) > 1
                                ? implode(' + ', array_map(
                                    static fn (string $method): string => sale_option_label($paymentLabels, $method),
                                    $paymentMethodsUsed
                                ))
                                : sale_option_label(
                                    $paymentLabels,
                                    $paymentMethodsUsed[0] ?? $sale['payment_method']
                                )
                        ) ?>

                    </dd>

                </div>


                <div>

                    <dt>
                        Estado
                    </dt>

                    <dd>

                        <span class="
                                status-badge
                                sale-status-<?= e(
                                    $sale['status']
                                ) ?>
                            ">

                            <?= e(
                                sale_option_label(
                                    $statusLabels,
                                    $sale['status']
                                )
                            ) ?>

                        </span>

                    </dd>

                </div>


                <?php if (
                    $sale[
                        'cancelled_at'
                    ] !== null
                ): ?>

                    <div>

                        <dt>
                            Cancelada
                        </dt>

                        <dd>

                            <?= e(
                                date(
                                    'd/m/Y H:i',
                                    strtotime(
                                        (string) 
                                        $sale[
                                            'cancelled_at'
                                        ]
                                    )
                                )
                            ) ?>

                        </dd>

                    </div>

                <?php endif; ?>

            </dl>

        </div>



        <!-- =================================================
             PAYMENTS
             ================================================= -->

        <?php if ($paymentsReady): ?>
            <div class="sale-view-meta-section sale-payments">
                <span class="sale-view-meta-title">
                    PAGOS
                </span>

                <?php if ($payments === []): ?>
                    <p class="sale-view-notes">Todavía no hay pagos.</p>
                <?php else: ?>
                    <ul class="sale-payment-list">
                        <?php foreach ($payments as $payment): ?>
                            <li>
                                <div>
                                    <strong><?= e(format_money($payment['amount'])) ?></strong>
                                    <?= e(sale_option_label($paymentLabels, $payment['method'])) ?>
                                    <span class="sale-payment-meta">
                                        <?= e(date('d/m/Y H:i', strtotime((string) $payment['paid_at']))) ?>
                                        <?= $payment['note'] ? '· ' . e($payment['note']) : '' ?>
                                    </span>
                                </div>
                                <?php if (admin_is_owner() && $sale['status'] !== 'cancelled'): ?>
                                    <form method="post" action="<?= e(APP_BASE_URL) ?>/admin/sales/update-status.php"
                                        onsubmit="return confirm('¿Eliminar este pago?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $sale['id'] ?>">
                                        <input type="hidden" name="action" value="delete_payment">
                                        <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                                        <button class="sale-payment-delete" type="submit" title="Eliminar pago">×</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <dl class="sale-meta">
                    <div>
                        <dt>Pagado</dt>
                        <dd><?= e(format_money($paidAmount)) ?></dd>
                    </div>
                    <div>
                        <dt><?= $balance < 0 ? 'A favor del cliente' : 'Saldo pendiente' ?></dt>
                        <dd><strong><?= e(format_money(abs($balance))) ?></strong></dd>
                    </div>
                </dl>

                <?php if ($canAddPayment): ?>
                    <form class="sale-payment-form" method="post" action="<?= e(APP_BASE_URL) ?>/admin/sales/update-status.php">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $sale['id'] ?>">
                        <input type="hidden" name="action" value="add_payment">
                        <div class="sale-payment-fields">
                            <input class="form-input" type="text" inputmode="decimal" name="amount"
                                value="<?= e(number_format($balance, 2, '.', '')) ?>" aria-label="Monto" required>
                            <select class="form-select" name="method" aria-label="Método">
                                <?php foreach ($paymentLabels as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $sale['payment_method'] === $value ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input class="form-input" type="text" name="note" maxlength="190" placeholder="Nota (opcional), ej. abono, referencia">
                        <button class="admin-button" type="submit">REGISTRAR PAGO O ABONO</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>



        <!-- =================================================
             NOTES
             ================================================= -->

        <?php if (
            !empty(
            $sale['notes']
        )
        ): ?>

            <div class="sale-view-meta-section">

                <span class="sale-view-meta-title">
                    NOTAS
                </span>

                <p class="sale-view-notes">

                    <?= nl2br(
                        e(
                            $sale[
                                'notes'
                            ]
                        )
                    ) ?>

                </p>

            </div>

        <?php endif; ?>



        <!-- =================================================
             ACTIONS
             ================================================= -->

        <div class="sale-actions">

            <a class="
                    admin-button
                " href="<?= e(
                    APP_BASE_URL
                ) ?>/admin/sales/invoice.php?id=<?= (int) $sale['id'] ?>" target="_blank" rel="noopener">
                VER / IMPRIMIR <?= e(sale_doc_type_titles()[$sale['doc_type']] ?? 'FACTURA') ?>
            </a>

            <?php if (!$isInvoice): ?>
                <form method="post" action="<?= e(APP_BASE_URL) ?>/admin/sales/update-status.php"
                    onsubmit="return confirm('¿Convertir en factura de venta? Se descontará el stock y contará como venta de hoy.');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $sale['id'] ?>">
                    <input type="hidden" name="action" value="to_invoice">
                    <button class="admin-button" type="submit">CONVERTIR EN FACTURA</button>
                </form>
            <?php endif; ?>

            <a class="admin-button admin-button-outline"
                href="<?= e(APP_BASE_URL) ?>/admin/sales/create.php?id=<?= (int) $sale['id'] ?>">
                EDITAR
            </a>

            <a class="admin-button admin-button-outline"
                href="<?= e(APP_BASE_URL) ?>/admin/workshop/create.php?sale_id=<?= (int) $sale['id'] ?>">
                CREAR PEDIDO AL TALLER
            </a>


            <?php if (
                $whatsappUrl !== null
            ): ?>

                <a class="
                        admin-button
                        admin-button-outline
                        sale-whatsapp-button
                    " href="<?= e(
                        $whatsappUrl
                    ) ?>" target="_blank" rel="noopener noreferrer">
                    ENVIAR COMPROBANTE POR WHATSAPP
                </a>

            <?php endif; ?>



            <?php if (
                $sale['status']
                === 'pending'
            ): ?>

                <form method="post" action="<?= e(
                    APP_BASE_URL
                ) ?>/admin/sales/update-status.php">

                    <input type="hidden" name="csrf_token" value="<?= e(
                        csrf_token()
                    ) ?>">

                    <input type="hidden" name="id" value="<?= (int) 
                        $sale['id']
                        ?>">

                    <input type="hidden" name="action" value="mark_paid">

                    <button class="admin-button" type="submit">
                        MARCAR COMO PAGADA
                    </button>

                </form>

            <?php endif; ?>



            <?php if (
                $sale['status']
                !== 'cancelled'
            ): ?>

                <form method="post" action="<?= e(
                    APP_BASE_URL
                ) ?>/admin/sales/update-status.php" onsubmit="return confirm(
                        '¿Cancelar esta venta? El stock se devolverá al inventario.'
                    );">

                    <input type="hidden" name="csrf_token" value="<?= e(
                        csrf_token()
                    ) ?>">

                    <input type="hidden" name="id" value="<?= (int) 
                        $sale['id']
                        ?>">

                    <input type="hidden" name="action" value="cancel">

                    <button class="
                            admin-button
                            admin-button-outline
                            table-action-danger
                        " type="submit">
                        CANCELAR VENTA
                    </button>

                </form>

            <?php endif; ?>

            <form method="post" action="<?= e(APP_BASE_URL) ?>/admin/sales/delete.php"
                onsubmit="return confirm('¿Eliminar <?= e($saleNumber) ?> por completo? No se puede deshacer.');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $sale['id'] ?>">
                <button class="admin-button admin-button-outline table-action-danger" type="submit">
                    ELIMINAR
                </button>
            </form>


        </div>

    </aside>


</div>


<?php

render_sales_layout_end();

?>