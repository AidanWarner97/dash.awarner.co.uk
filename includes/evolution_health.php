<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';
require_once __DIR__ . '/evolution_bunny.php';

function evolution_health_cache_file(): string
{
    $domain = project_settings('evolutioncdn')['domain'];
    return sys_get_temp_dir() . '/dashboard-evolution-health-' . substr(hash('sha256', $domain), 0, 16) . '.json';
}

function evolution_health_status(string $status): string
{
    return in_array($status, ['healthy', 'warning', 'error', 'building', 'idle'], true) ? $status : 'warning';
}

function evolution_health_normalize(array $payload, int $responseMilliseconds): array
{
    if (($payload['success'] ?? false) !== true || !is_array($payload['checks'] ?? null)) {
        throw new RuntimeException('The health endpoint returned an invalid response.');
    }

    $services = [];
    foreach ($payload['checks'] as $key => $check) {
        if (!is_array($check)) continue;
        $details = [];
        foreach (($check['details'] ?? $check) as $label => $value) {
            if (in_array($label, ['status', 'message'], true)) continue;
            if (is_bool($value)) $value = $value ? 'Yes' : 'No';
            elseif (is_array($value)) $value = implode(', ', array_map('strval', $value));
            elseif (!is_scalar($value) && $value !== null) continue;
            $details[ucwords(str_replace('_', ' ', (string) $label))] = (string) $value;
        }
        $services[] = [
            'key' => (string) $key,
            'name' => ucwords(str_replace('_', ' ', (string) $key)),
            'status' => evolution_health_status((string) ($check['status'] ?? 'warning')),
            'message' => (string) ($check['message'] ?? 'No status message supplied.'),
            'details' => $details,
        ];
    }

    return [
        'status' => evolution_health_status((string) ($payload['status'] ?? 'warning')),
        'checked_at' => (string) ($payload['timestamp'] ?? gmdate(DATE_ATOM)),
        'response_ms' => $responseMilliseconds,
        'services' => $services,
        'stale' => false,
        'error' => null,
    ];
}

function evolution_health_request(string $url): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'EvolutionDashboardHealth/1.0',
    ]);
    $started = microtime(true);
    $response = curl_exec($handle);
    $result = [
        'body' => $response,
        'milliseconds' => (int) round((microtime(true) - $started) * 1000),
        'status_code' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'error' => curl_error($handle),
    ];
    curl_close($handle);
    return $result;
}

function evolution_health_endpoint(): string
{
    $configured = trim((string) (getenv('EVOLUTION_CDN_HEALTH_URL') ?: ''));
    $url = $configured !== '' ? $configured : 'https://' . project_settings('evolutioncdn')['domain'] . '/api/health';
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
        throw new RuntimeException('The configured Evolution X CDN health URL is invalid.');
    }
    return $url;
}

function evolution_health_configured_endpoint(): string
{
    return trim((string) (getenv('EVOLUTION_CDN_HEALTH_URL') ?: ''));
}

function evolution_health_save_settings(array $input): void
{
    if (!updates_writes_enabled()) throw new RuntimeException('Health monitor changes are disabled.');
    $url = trim((string) ($input['health_url'] ?? ''));
    if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))) {
        throw new InvalidArgumentException('Enter a valid HTTP or HTTPS health endpoint URL.');
    }
    project_settings_write_environment(['EVOLUTION_CDN_HEALTH_URL' => $url]);
    @unlink(evolution_health_cache_file());
}

function evolution_health_local(string $remoteError, int $responseMilliseconds): array
{
    $started = microtime(true);
    $settings = project_settings('evolutioncdn');
    $services = [];

    try {
        $database = project_database_connection('evolutioncdn');
        $database->query('SELECT 1');
        $services[] = ['key' => 'database', 'name' => 'Database', 'status' => 'healthy', 'message' => 'MariaDB connection successful', 'details' => ['Database' => (string) $database->query('SELECT DATABASE()')->fetchColumn()]];
    } catch (Throwable $error) {
        $services[] = ['key' => 'database', 'name' => 'Database', 'status' => 'error', 'message' => 'MariaDB connection failed', 'details' => ['Error' => $error->getMessage()]];
    }

    try {
        if (!evolution_bunny_configured()) throw new RuntimeException('Bunny Storage is not configured.');
        $items = evolution_bunny_list();
        $bunny = evolution_bunny_settings();
        $services[] = ['key' => 'bunny_storage', 'name' => 'Bunny Storage', 'status' => 'healthy', 'message' => 'Storage Zone accessible', 'details' => ['Zone' => $bunny['zone'], 'Region' => $bunny['region'], 'Root Items' => (string) count($items)]];
    } catch (Throwable $error) {
        $services[] = ['key' => 'bunny_storage', 'name' => 'Bunny Storage', 'status' => 'error', 'message' => 'Storage Zone unavailable', 'details' => ['Error' => $error->getMessage()]];
    }

    $root = $settings['root'] !== '' ? realpath($settings['root']) : false;
    $sourceAvailable = $root !== false && is_dir($root) && is_readable($root);
    $services[] = [
        'key' => 'source_application',
        'name' => 'Source Application',
        'status' => $sourceAvailable ? 'healthy' : 'error',
        'message' => $sourceAvailable ? 'Application source is readable' : 'Application source is unavailable',
        'details' => $sourceAvailable ? ['API Modules' => (string) count(glob($root . '/modules/api/*.php') ?: []), 'Cron Jobs' => (string) count(glob($root . '/cron*.php') ?: [])] : [],
    ];

    $manifestPath = $sourceAvailable ? $root . '/data/manifests/status.json' : '';
    $manifest = $manifestPath !== '' && is_readable($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
    $manifestUpdated = is_array($manifest) ? (string) ($manifest['updated_at'] ?? $manifest['generated_at'] ?? '') : '';
    $manifestTimestamp = $manifestUpdated !== '' ? strtotime($manifestUpdated) : false;
    $manifestFresh = $manifestTimestamp !== false && $manifestTimestamp >= time() - 7200;
    $services[] = [
        'key' => 'bucket_manifest',
        'name' => 'Bucket Manifest',
        'status' => !is_array($manifest) ? 'warning' : ($manifestFresh ? 'healthy' : 'warning'),
        'message' => !is_array($manifest) ? 'Manifest status unavailable' : ($manifestFresh ? 'Manifest is current' : 'Manifest data is stale'),
        'details' => is_array($manifest) ? ['Status' => (string) ($manifest['status'] ?? 'unknown'), 'Updated' => $manifestUpdated] : [],
    ];

    $requiredScripts = ['cron.php', 'cron_push_queue.php', 'cron_bunny_sync.php', 'cron_bunny_watch.php', 'cron_hashes.php', 'cron_manifest_index.php', 'cron_daily_log_to_csv.php', 'cron_archive_monthly_logs.php'];
    $missingScripts = $sourceAvailable ? array_values(array_filter($requiredScripts, static fn(string $script): bool => !is_readable($root . '/' . $script))) : $requiredScripts;
    $services[] = [
        'key' => 'automation_workers',
        'name' => 'Automation Workers',
        'status' => !$missingScripts ? 'healthy' : 'error',
        'message' => !$missingScripts ? 'All worker scripts available' : count($missingScripts) . ' worker scripts unavailable',
        'details' => ['Available' => (string) (count($requiredScripts) - count($missingScripts)), 'Required' => (string) count($requiredScripts), 'Missing' => $missingScripts ? implode(', ', $missingScripts) : 'None'],
    ];

    $services[] = ['key' => 'public_health_api', 'name' => 'Public Health API', 'status' => 'warning', 'message' => 'Protected by Cloudflare; direct checks used', 'details' => ['Error' => $remoteError]];
    $requiredExtensions = ['curl', 'json', 'pdo', 'pdo_mysql'];
    $missingExtensions = array_values(array_filter($requiredExtensions, static fn(string $extension): bool => !extension_loaded($extension)));
    $services[] = ['key' => 'php_runtime', 'name' => 'PHP Runtime', 'status' => !$missingExtensions ? 'healthy' : 'error', 'message' => !$missingExtensions ? 'Required extensions loaded' : 'Required extensions missing', 'details' => ['PHP' => PHP_VERSION, 'Missing' => $missingExtensions ? implode(', ', $missingExtensions) : 'None']];

    $status = 'healthy';
    foreach ($services as $service) {
        if ($service['status'] === 'error') {
            $status = 'error';
            break;
        }
        if ($service['status'] === 'warning') $status = 'warning';
    }
    return [
        'status' => $status,
        'checked_at' => gmdate(DATE_ATOM),
        'response_ms' => $responseMilliseconds + (int) round((microtime(true) - $started) * 1000),
        'services' => $services,
        'stale' => false,
        'error' => null,
        'notice' => 'The public health API is protected by Cloudflare. Service status was collected directly by the dashboard.',
    ];
}

function evolution_health_monitor(bool $forceRefresh = false): array
{
    $cacheFile = evolution_health_cache_file();
    $cached = is_readable($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    if (!$forceRefresh && is_array($cached) && (int) ($cached['cached_at'] ?? 0) >= time() - 30 && is_array($cached['data'] ?? null)) {
        return $cached['data'];
    }

    try {
        if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is unavailable.');
        $request = evolution_health_request(evolution_health_endpoint());
        if ($request['body'] === false || $request['status_code'] !== 200) {
            $detail = trim(preg_replace('/\s+/', ' ', strip_tags((string) $request['body'])));
            $detail = $detail === '' ? '' : ': ' . mb_substr($detail, 0, 180);
            $message = $request['error'] !== '' ? $request['error'] : 'Health endpoint returned HTTP ' . $request['status_code'] . $detail . '.';
            $data = evolution_health_local($message, $request['milliseconds']);
        } else {
            $decoded = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
            $data = evolution_health_normalize($decoded, $request['milliseconds']);
        }
        $payload = json_encode(['cached_at' => time(), 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $cacheFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $payload, LOCK_EX) !== false) {
            if (!rename($temporary, $cacheFile)) @unlink($temporary);
        }
        return $data;
    } catch (Throwable $error) {
        if (is_array($cached['data'] ?? null)) {
            $cached['data']['stale'] = true;
            $cached['data']['error'] = $error->getMessage();
            return $cached['data'];
        }
        return [
            'status' => 'error',
            'checked_at' => gmdate(DATE_ATOM),
            'response_ms' => null,
            'services' => [],
            'stale' => false,
            'error' => $error->getMessage(),
        ];
    }
}
