<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sales-functions.php';
require_once __DIR__ . '/../../includes/finance-functions.php';

require_admin();

if (!sales_tables_exist($pdo) || !finance_tables_exist($pdo)) {
    header('Location: ' . APP_BASE_URL . '/admin/finance/');
    exit;
}

$filters = finance_filters_from_request($_GET);
$summary = finance_summary($pdo, $filters);
$months = finance_monthly($pdo, $filters);
$categories = expense_categories();

$expenses = $pdo->prepare(
    'SELECT * FROM expenses WHERE expense_date BETWEEN :from AND :to ORDER BY expense_date, id'
);
$expenses->execute(['from' => $filters['from'], 'to' => $filters['to']]);

$money = static fn (float|string|null $value): string => number_format((float) $value, 2, '.', '');

header('Content-Type: text/csv; charset=UTF-8');
header(
    'Content-Disposition: attachment; filename="finanzas-' .
    $filters['from'] . '-a-' . $filters['to'] . '.csv"'
);

$output = fopen('php://output', 'w');

// BOM so Excel opens the accents correctly.
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, ['Resumen', $filters['from'] . ' a ' . $filters['to']]);
fputcsv($output, ['Ventas', $money($summary['revenue'])]);
fputcsv($output, ['Costo de lo vendido', $money($summary['cost'])]);
fputcsv($output, ['Ganancia bruta', $money($summary['gross_profit'])]);
fputcsv($output, ['Gastos', $money($summary['expenses'])]);
fputcsv($output, ['Utilidad neta', $money($summary['net_profit'])]);
fputcsv($output, ['Unidades vendidas sin costo', $summary['units_without_cost']]);
fputcsv($output, []);

fputcsv($output, ['Mes', 'Ventas', 'Costo', 'Ganancia bruta', 'Gastos', 'Utilidad neta']);

foreach ($months as $month) {
    fputcsv($output, [
        finance_month_label($month['month']),
        $money($month['revenue']),
        $money($month['cost']),
        $money($month['gross_profit']),
        $money($month['expenses']),
        $money($month['net_profit']),
    ]);
}

fputcsv($output, []);
fputcsv($output, ['Fecha', 'Categoría', 'Descripción', 'Forma de pago', 'Monto', 'Nota']);

foreach ($expenses->fetchAll() as $expense) {
    fputcsv($output, [
        $expense['expense_date'],
        $categories[$expense['category']] ?? $expense['category'],
        $expense['description'],
        sale_option_label(sale_payment_methods(), $expense['payment_method']),
        $money($expense['amount']),
        $expense['notes'] ?? '',
    ]);
}

fclose($output);
