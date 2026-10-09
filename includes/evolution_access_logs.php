<?php

declare(strict_types=1);

require_once __DIR__ . '/evolutioncdn.php';

function evolution_access_log_filters(array $input): array
{
    $limited = (string) ($input['limited'] ?? 'all');
    if (!in_array($limited, ['all', 'yes', 'no'], true)) $limited = 'all';
    $date = static function (mixed $value): string {
        $value = trim((string) $value);
        if ($value === '') return '';
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : '';
    };
    return [
        'q' => mb_substr(trim((string) ($input['q'] ?? '')), 0, 200),
        'route' => mb_substr(trim((string) ($input['route'] ?? '')), 0, 128),
        'limited' => $limited,
        'from' => $date($input['from'] ?? ''),
        'to' => $date($input['to'] ?? ''),
        'page' => max(1, (int) ($input['page'] ?? 1)),
    ];
}

function evolution_access_logs(array $input, int $perPage = 50): array
{
    $filters = evolution_access_log_filters($input);
    $database = project_database_connection('evolutioncdn');
    if (!evolutioncdn_table_exists($database, 'requests')) throw new RuntimeException('The CDN requests table is unavailable.');
    $hasIncidents = evolutioncdn_table_exists($database, 'rateLimitedIncidents');
    $where = ['1=1'];
    $params = [];
    if ($filters['q'] !== '') {
        $where[] = '(request.ip_address LIKE :ip OR request.user_id LIKE :user_id OR request.route_name LIKE :route_search OR request.file_path LIKE :file_path OR request.user_agent LIKE :user_agent)';
        $search = '%' . $filters['q'] . '%';
        $params += [':ip' => $search, ':user_id' => $search, ':route_search' => $search, ':file_path' => $search, ':user_agent' => $search];
    }
    if ($filters['route'] !== '') {
        $where[] = 'request.route_name = :route';
        $params[':route'] = $filters['route'];
    }
    if ($filters['from'] !== '') {
        $where[] = 'request.created_at >= :from_date';
        $params[':from_date'] = $filters['from'] . ' 00:00:00';
    }
    if ($filters['to'] !== '') {
        $where[] = 'request.created_at < :to_date';
        $params[':to_date'] = (new DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }
    if ($hasIncidents && $filters['limited'] !== 'all') {
        $where[] = ($filters['limited'] === 'no' ? 'NOT ' : '') . 'EXISTS (SELECT 1 FROM rateLimitedIncidents incident_filter WHERE incident_filter.request_id = request.id)';
    }
    $whereSql = implode(' AND ', $where);
    $count = $database->prepare("SELECT COUNT(*) FROM requests request WHERE {$whereSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $perPage = max(10, min(100, $perPage));
    $pages = max(1, (int) ceil($total / $perPage));
    $filters['page'] = min($filters['page'], $pages);
    $offset = ($filters['page'] - 1) * $perPage;

    $incidentColumns = $hasIncidents
        ? "EXISTS (SELECT 1 FROM rateLimitedIncidents incident WHERE incident.request_id = request.id) AS rate_limited,
           (SELECT incident.action_taken FROM rateLimitedIncidents incident WHERE incident.request_id = request.id ORDER BY incident.id DESC LIMIT 1) AS rate_limit_action"
        : '0 AS rate_limited, NULL AS rate_limit_action';
    $statement = $database->prepare("SELECT request.id, request.user_id, request.ip_address, request.route_name, request.file_path, request.user_agent, request.created_at, {$incidentColumns}
        FROM requests request WHERE {$whereSql} ORDER BY request.created_at DESC, request.id DESC LIMIT {$perPage} OFFSET {$offset}");
    $statement->execute($params);

    $routes = $database->query('SELECT DISTINCT route_name FROM requests WHERE route_name <> "" ORDER BY route_name')->fetchAll(PDO::FETCH_COLUMN);
    $summary = ['all_time' => (int) $database->query('SELECT COUNT(*) FROM requests')->fetchColumn(), 'today' => 0, 'limited' => 0];
    $summary['today'] = (int) $database->query('SELECT COUNT(*) FROM requests WHERE created_at >= CURRENT_DATE')->fetchColumn();
    if ($hasIncidents) $summary['limited'] = (int) $database->query('SELECT COUNT(*) FROM rateLimitedIncidents WHERE created_at >= CURRENT_DATE')->fetchColumn();

    return ['rows' => $statement->fetchAll(), 'filters' => $filters, 'routes' => $routes, 'total' => $total, 'pages' => $pages, 'per_page' => $perPage, 'summary' => $summary];
}