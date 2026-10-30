<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/sales-query.php';

require_admin();

if (!sales_tables_exist($pdo)) {
    header('Location: ' . APP_BASE_URL . '/admin/sales/');
    exit;
}

$filters = sales_filters_from_request($_GET);

[$where, $parameters] = sales_where_clause($filters);

/*
|--------------------------------------------------------------------------
| One Row Per Sold Item
|--------------------------------------------------------------------------
*/

$statement = $pdo->prepare(
    'SELECT
        s.id,
        s.sale_number,
        s.doc_type,
        s.sold_at,
        s.status,
        s.channel,
        s.payment_method,
        s.customer_name,
        s.customer_phone,
        s.discount,
        s.total,
        si.source,
        si.product_code,
        si.product_name,
        si.color_name,
        si.size,
        si.quantity,
        si.unit_price,
        si.line_total
     FROM sales s
     INNER JOIN sale_items si
        ON si.sale_id = s.id
     WHERE ' . $where . '
     ORDER BY s.sold_at ASC, s.id ASC, si.id ASC'
);

$statement->execute($parameters);

$filename = sprintf(
    'ventas-%s-a-%s.csv',
    $filters['from'],
    $filters['to']
);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'wb');

// BOM so Excel opens accents correctly.
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'Número',
    'Tipo',
    'Fecha',
    'Estado',
    'Canal',
    'Pago',
    'Cliente',
    'Teléfono',
    'Origen',
    'Código',
    'Producto',
    'Color',
    'Talla',
    'Cantidad',
    'Precio unitario',
    'Total línea',
    'Descuento venta',
    'Total venta',
]);

// Neutralise values Excel would treat as formulas.
$safe = static function (mixed $value): string {
    $value = (string) $value;

    return preg_match('/^[=+\-@\t\r]/', $value) === 1
        ? "'" . $value
        : $value;
};

while ($row = $statement->fetch()) {
    fputcsv($output, [
        $row['sale_number'] ?: sale_number_for_id((int) $row['id']),
        sale_option_label(sale_doc_types(), $row['doc_type']),
        $row['sold_at'],
        sale_option_label(sale_statuses(), $row['status']),
        sale_option_label(sale_channels(), $row['channel']),
        sale_option_label(sale_payment_methods(), $row['payment_method']),
        $safe($row['customer_name']),
        $safe($row['customer_phone']),
        sale_option_label(sale_item_sources(), $row['source']),
        $safe($row['product_code']),
        $safe($row['product_name']),
        $safe($row['color_name']),
        $safe($row['size']),
        (int) $row['quantity'],
        $row['unit_price'],
        $row['line_total'],
        $row['discount'],
        $row['total'],
    ]);
}

fclose($output);
