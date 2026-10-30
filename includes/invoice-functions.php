<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Invoice Settings
|--------------------------------------------------------------------------
|
| Datos del negocio que salen en la factura. Se editan en
| Administración > Configuración (includes/settings-functions.php).
| INVOICE_SHOW_TAX = false: la factura no muestra IVA (total = productos).
| INVOICE_PRICES_INCLUDE_TAX = true: los precios de venta ya traen IVA,
| la factura lo desglosa. En false, el IVA se suma al total.
|
*/

require_once __DIR__ . '/settings-functions.php';

define('INVOICE_BUSINESS_NAME', business_name());
define('INVOICE_BUSINESS_TAX_ID', setting('business_tax_id'));
define('INVOICE_BUSINESS_ADDRESS', setting('business_address'));
define('INVOICE_BUSINESS_PHONE', setting('business_phone') ?: setting('whatsapp_number'));
define('INVOICE_BUSINESS_INSTAGRAM', setting('instagram_user') !== '' ? '@' . social_handle('instagram_user') : '');

define('INVOICE_SHOW_TAX', tax_enabled());
define('INVOICE_TAX_LABEL', setting('tax_label'));
define('INVOICE_TAX_RATE', tax_rate());
define('INVOICE_PRICES_INCLUDE_TAX', prices_include_tax());


/*
|--------------------------------------------------------------------------
| Invoice Totals
|--------------------------------------------------------------------------
|
| Returns subtotal, discount, taxable base, tax and total for a sale.
|
*/

function invoice_totals(array $sale): array
{
    $subtotal = round((float) $sale['subtotal'], 2);
    $discount = round((float) $sale['discount'], 2);
    $afterDiscount = round(max(0, $subtotal - $discount), 2);

    if (!INVOICE_SHOW_TAX) {
        $total = round((float) $sale['total'], 2);
        $base = $total;
        $tax = 0.0;
    } elseif (INVOICE_PRICES_INCLUDE_TAX) {
        $total = round((float) $sale['total'], 2);
        $base = round($total / (1 + INVOICE_TAX_RATE), 2);
        $tax = round($total - $base, 2);
    } else {
        $base = $afterDiscount;
        $tax = round($base * INVOICE_TAX_RATE, 2);
        $total = round($base + $tax, 2);
    }

    return [
        'subtotal' => $subtotal,
        'discount' => $discount,
        'base' => $base,
        'tax' => $tax,
        'total' => $total,
    ];
}


function invoice_tax_label(): string
{
    return sprintf(
        '%s (%s%%)',
        INVOICE_TAX_LABEL,
        rtrim(rtrim(number_format(INVOICE_TAX_RATE * 100, 2), '0'), '.')
    );
}
