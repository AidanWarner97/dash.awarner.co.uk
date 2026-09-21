<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';

function generation_logs_summary(PDO $database): array
{
    $table = project_database_table('tileimagegen', 'generate_log');
    $row = $database->query(
        "SELECT COUNT(*) AS total,
                SUM(status = 'generated') AS generated,
                SUM(status = 'error') AS errors,
                SUM(downloaded = 1) AS downloaded
         FROM {$table}"
    )->fetch();

    return [
        'total' => (int) ($row['total'] ?? 0),
        'generated' => (int) ($row['generated'] ?? 0),
        'errors' => (int) ($row['errors'] ?? 0),
        'downloaded' => (int) ($row['downloaded'] ?? 0),
    ];
}

function generation_logs_list(PDO $database, array $filters): array
{
    $table = project_database_table('tileimagegen', 'generate_log');
    $sql = "SELECT id, request_id, tile_name, image_file_count, tile_size_width, tile_size_height, layout, status, downloaded, created_at
            FROM {$table} WHERE 1=1";
    $params = [];

    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $sql .= ' AND (tile_name LIKE :tile_name OR request_id LIKE :request_id)';
        $params[':tile_name'] = '%' . $search . '%';
        $params[':request_id'] = '%' . $search . '%';
    }

    $status = (string) ($filters['status'] ?? 'all');
    if (in_array($status, ['generated', 'error'], true)) {
        $sql .= ' AND status = :status';
        $params[':status'] = $status;
    }

    $downloaded = (string) ($filters['downloaded'] ?? 'all');
    if (in_array($downloaded, ['0', '1'], true)) {
        $sql .= ' AND downloaded = :downloaded';
        $params[':downloaded'] = (int) $downloaded;
    }

    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 200';
    $statement = $database->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function generation_logs_find(PDO $database, int $id): ?array
{
    $table = project_database_table('tileimagegen', 'generate_log');
    $statement = $database->prepare("SELECT * FROM {$table} WHERE id = :id LIMIT 1");
    $statement->execute([':id' => $id]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}