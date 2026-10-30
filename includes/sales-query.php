<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Sales Filters
|--------------------------------------------------------------------------
|
| Reads the list filters from the query string and builds the
| WHERE clause shared by the sales list and the CSV export.
|
*/

function sales_filters_from_request(array $query): array
{
    $from = is_string($query['from'] ?? null) ? $query['from'] : '';
    $to = is_string($query['to'] ?? null) ? $query['to'] : '';

    $isDate = static fn (string $value): bool =>
        DateTime::createFromFormat('Y-m-d', $value) !== false &&
        DateTime::createFromFormat('Y-m-d', $value)->format('Y-m-d') === $value;

    if (!$isDate($from)) {
        $from = date('Y-m-01');
    }

    if (!$isDate($to)) {
        $to = date('Y-m-d');
    }

    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $status = is_string($query['status'] ?? null) ? $query['status'] : '';

    if (!isset(sale_statuses()[$status])) {
        $status = '';
    }

    $channel = is_string($query['channel'] ?? null) ? $query['channel'] : '';

    if (!isset(sale_channels()[$channel])) {
        $channel = '';
    }

    $docType = is_string($query['doc_type'] ?? null) ? $query['doc_type'] : '';

    if (!isset(sale_doc_types()[$docType])) {
        $docType = '';
    }

    $search = is_string($query['q'] ?? null) ? trim($query['q']) : '';

    return [
        'from' => $from,
        'to' => $to,
        'status' => $status,
        'channel' => $channel,
        'doc_type' => $docType,
        'q' => mb_substr($search, 0, 100),
    ];
}

function sales_where_clause(array $filters): array
{
    $conditions = [
        's.sold_at >= :from',
        's.sold_at < DATE_ADD(:to, INTERVAL 1 DAY)',
    ];

    $parameters = [
        'from' => $filters['from'] . ' 00:00:00',
        'to' => $filters['to'],
    ];

    if ($filters['status'] !== '') {
        $conditions[] = 's.status = :status';
        $parameters['status'] = $filters['status'];
    }

    if (($filters['doc_type'] ?? '') !== '') {
        $conditions[] = 's.doc_type = :doc_type';
        $parameters['doc_type'] = $filters['doc_type'];
    }

    if ($filters['channel'] !== '') {
        $conditions[] = 's.channel = :channel';
        $parameters['channel'] = $filters['channel'];
    }

    if ($filters['q'] !== '') {
        $conditions[] = '(
            s.sale_number LIKE :q1
            OR s.customer_name LIKE :q2
            OR s.customer_phone LIKE :q3
            OR EXISTS (
                SELECT 1
                FROM sale_items si_search
                WHERE
                    si_search.sale_id = s.id
                    AND (
                        si_search.product_name LIKE :q4
                        OR si_search.product_code LIKE :q5
                    )
            )
        )';

        $like = '%' . addcslashes($filters['q'], '%_\\') . '%';

        foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $key) {
            $parameters[$key] = $like;
        }
    }

    return [
        implode(' AND ', $conditions),
        $parameters,
    ];
}
