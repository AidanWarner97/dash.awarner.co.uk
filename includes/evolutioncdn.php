<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';

function evolutioncdn_period(int $days): int
{
    return in_array($days, [7, 30, 90], true) ? $days : 7;
}

function evolutioncdn_table_exists(PDO $database, string $table): bool
{
    $statement = $database->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $statement->execute([':table' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

function evolutioncdn_source_status(string $root): array
{
    $status = [
        'available' => false,
        'cron_jobs' => 0,
        'api_modules' => 0,
        'manifest' => null,
        'error' => null,
    ];
    $resolved = $root !== '' ? realpath($root) : false;
    if ($resolved === false || !is_dir($resolved)) {
        $status['error'] = 'Set the Evolution X CDN source directory in project settings.';
        return $status;
    }

    $status['available'] = true;
    $status['cron_jobs'] = count(glob($resolved . '/cron*.php') ?: []);
    $status['api_modules'] = count(glob($resolved . '/modules/api/*.php') ?: []);
    $manifestFile = $resolved . '/data/manifests/status.json';
    if (is_readable($manifestFile)) {
        try {
            $manifest = json_decode((string) file_get_contents($manifestFile), true, 16, JSON_THROW_ON_ERROR);
            if (is_array($manifest)) {
                $status['manifest'] = [
                    'updated_at' => (string) ($manifest['updated_at'] ?? $manifest['generated_at'] ?? ''),
                    'status' => (string) ($manifest['status'] ?? 'available'),
                ];
            }
        } catch (JsonException) {
            $status['error'] = 'The local manifest status file is not valid JSON.';
        }
    }
    return $status;
}

function evolutioncdn_overview_cache_file(int $days): string
{
    $settings = project_settings('evolutioncdn');
    $identity = hash('sha256', implode('|', [$settings['db_dsn'], $settings['db_name'], $settings['db_table_prefix'], $settings['root']]));
    return sys_get_temp_dir() . '/dashboard-evolution-overview-' . substr($identity, 0, 16) . '-' . $days . '.json';
}

function evolutioncdn_overview_cache_ttl(): int
{
    $configured = filter_var(getenv('EVOLUTION_CDN_OVERVIEW_CACHE_SECONDS') ?: 60, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 3600]]);
    return $configured === false ? 60 : $configured;
}

function evolutioncdn_overview_data(int $requestedDays = 7): array
{
    $days = evolutioncdn_period($requestedDays);
    $cacheFile = evolutioncdn_overview_cache_file($days);
    $cached = is_readable($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    if (is_array($cached) && (int) ($cached['cached_at'] ?? 0) >= time() - evolutioncdn_overview_cache_ttl() && is_array($cached['data'] ?? null)) {
        return $cached['data'];
    }

    try {
        $data = evolutioncdn_overview_uncached($days);
        $payload = json_encode(['cached_at' => time(), 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $cacheFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $payload, LOCK_EX) !== false) {
            if (!rename($temporary, $cacheFile)) @unlink($temporary);
        }
        return $data;
    } catch (Throwable $error) {
        if (is_array($cached['data'] ?? null)) return $cached['data'];
        throw $error;
    }
}

function evolutioncdn_overview_uncached(int $days): array
{
    $settings = project_settings('evolutioncdn');
    $data = [
        'period' => $days,
        'downloads' => ['available' => false, 'period' => 0, 'all_time' => 0, 'top' => [], 'error' => null],
        'requests' => ['available' => false, 'period' => 0, 'limited' => 0, 'error' => null],
        'queue' => ['available' => false, 'pending' => 0, 'failed' => 0, 'recent' => [], 'error' => null],
        'source' => evolutioncdn_source_status($settings['root']),
    ];

    try {
        $database = project_database_connection('evolutioncdn');
        $since = (new DateTimeImmutable('now'))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');

        if (evolutioncdn_table_exists($database, 'download_stats')) {
            $statement = $database->prepare('SELECT COUNT(*) FROM download_stats WHERE download_time >= :since');
            $statement->execute([':since' => $since]);
            $data['downloads']['period'] = (int) $statement->fetchColumn();
            $data['downloads']['all_time'] = (int) $database->query('SELECT COUNT(*) FROM download_stats')->fetchColumn();
            $data['downloads']['top'] = $database->query("SELECT COALESCE(NULLIF(folder, ''), 'Unknown') AS device, COUNT(*) AS total FROM download_stats GROUP BY folder ORDER BY total DESC LIMIT 5")->fetchAll();
            $data['downloads']['available'] = true;
        } else {
            $data['downloads']['error'] = 'The download_stats table is not available.';
        }

        if (evolutioncdn_table_exists($database, 'requests')) {
            $statement = $database->prepare('SELECT COUNT(*) FROM requests WHERE created_at >= :since');
            $statement->execute([':since' => $since]);
            $data['requests']['period'] = (int) $statement->fetchColumn();
            $data['requests']['available'] = true;
        }
        if (evolutioncdn_table_exists($database, 'rateLimitedIncidents')) {
            $statement = $database->prepare('SELECT COUNT(*) FROM rateLimitedIncidents WHERE created_at >= :since');
            $statement->execute([':since' => $since]);
            $data['requests']['limited'] = (int) $statement->fetchColumn();
            $data['requests']['available'] = true;
        }

        if (evolutioncdn_table_exists($database, 'push_release_queue')) {
            $states = $database->query('SELECT status, COUNT(*) AS total FROM push_release_queue GROUP BY status')->fetchAll();
            foreach ($states as $state) {
                $status = (string) $state['status'];
                if (in_array($status, ['queued', 'processing'], true)) $data['queue']['pending'] += (int) $state['total'];
                if ($status === 'failed') $data['queue']['failed'] = (int) $state['total'];
            }
            $data['queue']['recent'] = $database->query('SELECT id, codename, version, build_type, status, created_at FROM push_release_queue ORDER BY created_at DESC, id DESC LIMIT 6')->fetchAll();
            $data['queue']['available'] = true;
        } else {
            $data['queue']['error'] = 'The push release queue table is not available.';
        }
    } catch (Throwable) {
        $message = 'Configure the Evolution X CDN MariaDB connection to load live statistics.';
        $data['downloads']['error'] ??= $message;
        $data['requests']['error'] ??= $message;
        $data['queue']['error'] ??= $message;
    }

    return $data;
}