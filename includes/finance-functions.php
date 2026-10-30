<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Finance
|--------------------------------------------------------------------------
|
| Product cost, cost per sale item, expenses and the profit summary.
| Columns and the expenses table come from
| database/migrations/005_finance.sql; until it is imported every helper
| here does nothing, so the rest of the panel keeps working.
|
*/

function finance_tables_exist(PDO $pdo): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $pdo->query('SELECT cost_price FROM products LIMIT 1');
        $pdo->query('SELECT unit_cost FROM sale_items LIMIT 1');
        $pdo->query('SELECT id FROM expenses LIMIT 1');

        $exists = true;
    } catch (PDOException $exception) {
        $exists = false;
    }

    return $exists;
}

function expense_categories(): array
{
    return [
        'materials' => 'Materiales',
        'labor' => 'Mano de obra',
        'rent' => 'Alquiler',
        'utilities' => 'Servicios (luz, agua, internet)',
        'shipping' => 'Envíos y transporte',
        'marketing' => 'Publicidad',
        'packaging' => 'Empaque',
        'fees' => 'Comisiones y bancos',
        'other' => 'Otros',
    ];
}

function render_finance_migration_notice(): void
{
    ?>
    <section class="empty-state">
        <h2>
            Falta crear las tablas de finanzas
        </h2>
        <p>
            En <a href="<?= e(APP_BASE_URL) ?>/admin/settings/">Configuración</a> pulsa
            <strong>ACTUALIZAR BASE DE DATOS</strong> (solo el dueño puede hacerlo). Luego recarga esta página.
        </p>
    </section>
    <?php
}


/*
|--------------------------------------------------------------------------
| Finance Filters
|--------------------------------------------------------------------------
*/

function finance_filters_from_request(array $query): array
{
    $isDate = static fn (string $value): bool =>
        DateTime::createFromFormat('Y-m-d', $value) !== false &&
        DateTime::createFromFormat('Y-m-d', $value)->format('Y-m-d') === $value;

    $from = is_string($query['from'] ?? null) ? $query['from'] : '';
    $to = is_string($query['to'] ?? null) ? $query['to'] : '';

    if (!$isDate($from)) {
        $from = date('Y-m-01');
    }

    if (!$isDate($to)) {
        $to = date('Y-m-d');
    }

    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    return ['from' => $from, 'to' => $to];
}


/*
|--------------------------------------------------------------------------
| Product Cost (products create / edit)
|--------------------------------------------------------------------------
*/

function product_cost_form_value(bool $isPost, ?array $product = null): string
{
    if ($isPost) {
        return trim((string) ($_POST['cost_price'] ?? ''));
    }

    return (string) ($product['cost_price'] ?? '');
}

/**
 * Returns [['cost_price' => string|null], error message].
 */
function validate_product_cost(string $value): array
{
    $value = str_replace(',', '.', $value);

    if ($value === '') {
        return [['cost_price' => null], ''];
    }

    if (!is_numeric($value) || (float) $value < 0) {
        return [null, 'Ingresa un costo válido o déjalo vacío.'];
    }

    return [['cost_price' => number_format((float) $value, 2, '.', '')], ''];
}

/**
 * Only users who can see finance see or change costs (see includes/auth.php).
 */
function admin_can_see_costs(): bool
{
    return !function_exists('admin_can') || admin_can('finance');
}

function save_product_cost(PDO $pdo, int $productId, ?array $values): void
{
    if ($values === null || !finance_tables_exist($pdo) || !admin_can_see_costs()) {
        return;
    }

    $pdo->prepare(
        'UPDATE products SET cost_price = :cost_price WHERE id = :id'
    )->execute($values + ['id' => $productId]);
}

function render_product_cost_field(PDO $pdo, string $value): void
{
    if (!finance_tables_exist($pdo) || !admin_can_see_costs()) {
        return;
    }
    ?>
    <div class="form-group">
        <label class="form-label" for="cost_price">
            Costo (<?= e(currency_symbol()) ?>)
        </label>
        <input class="form-input" id="cost_price" name="cost_price" type="number"
            min="0" step="0.01" value="<?= e($value) ?>">
        <p class="form-hint">
            Lo que te cuesta producir o comprar un par. No se muestra en el catálogo.
        </p>
    </div>
    <?php
}


/*
|--------------------------------------------------------------------------
| Cost Per Sale
|--------------------------------------------------------------------------
*/

/**
 * Saves the cost typed for a made-to-order item on its product.
 */
function save_made_to_order_cost(PDO $pdo, int $productId, ?float $cost): void
{
    if ($cost === null || !finance_tables_exist($pdo)) {
        return;
    }

    $pdo->prepare(
        'UPDATE products SET cost_price = :cost_price WHERE id = :id'
    )->execute([
        'cost_price' => number_format($cost, 2, '.', ''),
        'id' => $productId,
    ]);
}

/**
 * Copies each product's current cost onto the sale items that have none.
 * With $saleId it only touches that sale; without it, every past sale.
 * Returns the number of items updated.
 */
function fill_sale_item_costs(PDO $pdo, ?int $saleId = null): int
{
    if (!finance_tables_exist($pdo)) {
        return 0;
    }

    $sql =
        'UPDATE sale_items si
         INNER JOIN products p
            ON p.id = si.product_id
         SET si.unit_cost = p.cost_price
         WHERE
            si.unit_cost IS NULL
            AND p.cost_price IS NOT NULL';

    $parameters = [];

    if ($saleId !== null) {
        $sql .= ' AND si.sale_id = :sale_id';
        $parameters['sale_id'] = $saleId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return $statement->rowCount();
}


/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
|
| Sales are counted by their total (after discount), without cancelled
| ones. Cost of goods is quantity x unit cost of each item.
|
*/

function finance_summary(PDO $pdo, array $filters): array
{
    $range = [
        'from' => $filters['from'] . ' 00:00:00',
        'to' => $filters['to'],
    ];

    $sales = $pdo->prepare(
        'SELECT
            COUNT(*) AS sales_count,
            COALESCE(SUM(s.total), 0) AS revenue,
            COALESCE(SUM(s.discount), 0) AS discounts
         FROM sales s
         WHERE
            s.status != \'cancelled\' AND s.doc_type = \'invoice\'
            AND s.sold_at >= :from
            AND s.sold_at < DATE_ADD(:to, INTERVAL 1 DAY)'
    );
    $sales->execute($range);
    $salesRow = $sales->fetch();

    $items = $pdo->prepare(
        'SELECT
            COALESCE(SUM(si.quantity), 0) AS units,
            COALESCE(SUM(si.quantity * si.unit_cost), 0) AS cost,
            COALESCE(SUM(CASE WHEN si.unit_cost IS NULL THEN si.quantity ELSE 0 END), 0) AS units_without_cost,
            COALESCE(SUM(CASE WHEN si.unit_cost IS NULL THEN si.line_total ELSE 0 END), 0) AS revenue_without_cost
         FROM sale_items si
         INNER JOIN sales s
            ON s.id = si.sale_id
         WHERE
            s.status != \'cancelled\' AND s.doc_type = \'invoice\'
            AND s.sold_at >= :from
            AND s.sold_at < DATE_ADD(:to, INTERVAL 1 DAY)'
    );
    $items->execute($range);
    $itemsRow = $items->fetch();

    $expenses = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM expenses
         WHERE expense_date BETWEEN :from AND :to'
    );
    $expenses->execute(['from' => $filters['from'], 'to' => $filters['to']]);

    $revenue = (float) $salesRow['revenue'];
    $cost = (float) $itemsRow['cost'];
    $expensesTotal = (float) $expenses->fetchColumn();
    $grossProfit = $revenue - $cost;
    $netProfit = $grossProfit - $expensesTotal;

    return [
        'sales_count' => (int) $salesRow['sales_count'],
        'revenue' => $revenue,
        'discounts' => (float) $salesRow['discounts'],
        'units' => (int) $itemsRow['units'],
        'cost' => $cost,
        'gross_profit' => $grossProfit,
        'expenses' => $expensesTotal,
        'net_profit' => $netProfit,
        'gross_margin' => $revenue > 0 ? $grossProfit / $revenue * 100 : null,
        'net_margin' => $revenue > 0 ? $netProfit / $revenue * 100 : null,
        'units_without_cost' => (int) $itemsRow['units_without_cost'],
        'revenue_without_cost' => (float) $itemsRow['revenue_without_cost'],
    ];
}

/**
 * One row per month in the range: revenue, cost, expenses, profit.
 */
function finance_monthly(PDO $pdo, array $filters): array
{
    $range = [
        'from' => $filters['from'] . ' 00:00:00',
        'to' => $filters['to'],
    ];

    $months = [];
    $cursor = new DateTime(substr($filters['from'], 0, 7) . '-01');
    $last = new DateTime(substr($filters['to'], 0, 7) . '-01');

    while ($cursor <= $last) {
        $months[$cursor->format('Y-m')] = [
            'month' => $cursor->format('Y-m'),
            'revenue' => 0.0,
            'cost' => 0.0,
            'expenses' => 0.0,
        ];
        $cursor->modify('+1 month');
    }

    $sales = $pdo->prepare(
        'SELECT DATE_FORMAT(s.sold_at, \'%Y-%m\') AS month, SUM(s.total) AS revenue
         FROM sales s
         WHERE
            s.status != \'cancelled\' AND s.doc_type = \'invoice\'
            AND s.sold_at >= :from
            AND s.sold_at < DATE_ADD(:to, INTERVAL 1 DAY)
         GROUP BY month'
    );
    $sales->execute($range);

    foreach ($sales->fetchAll() as $row) {
        if (isset($months[$row['month']])) {
            $months[$row['month']]['revenue'] = (float) $row['revenue'];
        }
    }

    $costs = $pdo->prepare(
        'SELECT DATE_FORMAT(s.sold_at, \'%Y-%m\') AS month, SUM(si.quantity * si.unit_cost) AS cost
         FROM sale_items si
         INNER JOIN sales s
            ON s.id = si.sale_id
         WHERE
            s.status != \'cancelled\' AND s.doc_type = \'invoice\'
            AND s.sold_at >= :from
            AND s.sold_at < DATE_ADD(:to, INTERVAL 1 DAY)
         GROUP BY month'
    );
    $costs->execute($range);

    foreach ($costs->fetchAll() as $row) {
        if (isset($months[$row['month']])) {
            $months[$row['month']]['cost'] = (float) $row['cost'];
        }
    }

    $expenses = $pdo->prepare(
        'SELECT DATE_FORMAT(expense_date, \'%Y-%m\') AS month, SUM(amount) AS total
         FROM expenses
         WHERE expense_date BETWEEN :from AND :to
         GROUP BY month'
    );
    $expenses->execute(['from' => $filters['from'], 'to' => $filters['to']]);

    foreach ($expenses->fetchAll() as $row) {
        if (isset($months[$row['month']])) {
            $months[$row['month']]['expenses'] = (float) $row['total'];
        }
    }

    foreach ($months as &$month) {
        $month['gross_profit'] = $month['revenue'] - $month['cost'];
        $month['net_profit'] = $month['gross_profit'] - $month['expenses'];
    }
    unset($month);

    return array_values($months);
}

/**
 * Profit per product in the range, best first. Uses line totals, so a
 * sale-level discount is not spread across its products.
 */
function finance_product_profit(PDO $pdo, array $filters, int $limit = 15): array
{
    $statement = $pdo->prepare(
        'SELECT
            si.product_code,
            MAX(si.product_name) AS product_name,
            SUM(si.quantity) AS units,
            SUM(si.line_total) AS revenue,
            SUM(si.quantity * si.unit_cost) AS cost,
            SUM(CASE WHEN si.unit_cost IS NULL THEN si.quantity ELSE 0 END) AS units_without_cost
         FROM sale_items si
         INNER JOIN sales s
            ON s.id = si.sale_id
         WHERE
            s.status != \'cancelled\' AND s.doc_type = \'invoice\'
            AND s.sold_at >= :from
            AND s.sold_at < DATE_ADD(:to, INTERVAL 1 DAY)
         GROUP BY si.product_code
         ORDER BY (SUM(si.line_total) - COALESCE(SUM(si.quantity * si.unit_cost), 0)) DESC
         LIMIT ' . max(1, $limit)
    );
    $statement->execute([
        'from' => $filters['from'] . ' 00:00:00',
        'to' => $filters['to'],
    ]);

    return $statement->fetchAll();
}

function finance_expenses_by_category(PDO $pdo, array $filters): array
{
    $statement = $pdo->prepare(
        'SELECT category, SUM(amount) AS total, COUNT(*) AS entries
         FROM expenses
         WHERE expense_date BETWEEN :from AND :to
         GROUP BY category
         ORDER BY total DESC'
    );
    $statement->execute(['from' => $filters['from'], 'to' => $filters['to']]);

    return $statement->fetchAll();
}

/**
 * Like format_money(), with the minus sign before C$: -C$2,850.00
 */
function finance_money(float|string|null $amount): string
{
    $amount = (float) $amount;

    return ($amount < 0 ? '-' : '') . format_money(abs($amount));
}

function format_percent(?float $value): string
{
    return $value === null ? '—' : number_format($value, 1) . '%';
}

function finance_month_label(string $month): string
{
    $names = [
        '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
        '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
        '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre',
    ];

    return ($names[substr($month, 5, 2)] ?? $month) . ' ' . substr($month, 0, 4);
}
