<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';

function discord_bots_remote_settings(): array
{
    return [
        'url' => rtrim(trim((string) (getenv('DISCORD_BOTS_API_URL') ?: '')), '/'),
        'identifier' => trim((string) (getenv('DISCORD_BOTS_API_IDENTIFIER') ?: '')),
        'has_secret' => trim((string) (getenv('DISCORD_BOTS_API_SECRET') ?: '')) !== '',
    ];
}

function discord_bots_remote_configured(): bool
{
    $settings = discord_bots_remote_settings();
    return $settings['url'] !== '' && $settings['identifier'] !== '' && $settings['has_secret'];
}

function discord_bots_cache_file(): string
{
    $settings = discord_bots_remote_settings();
    $key = $settings['url'] . '|' . $settings['identifier'];
    return dashboard_cache_directory() . '/discord-bots-' . substr(hash('sha256', $key), 0, 16) . '.json';
}

function discord_bots_save_settings(array $input): void
{
    if (!updates_writes_enabled()) throw new RuntimeException('Discord Bots changes are disabled.');

    $url = rtrim(trim((string) ($input['api_url'] ?? '')), '/');
    $identifier = trim((string) ($input['api_identifier'] ?? ''));
    if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))) {
        throw new InvalidArgumentException('Enter a valid HTTP or HTTPS Professor API URL.');
    }
    if ($identifier !== '' && preg_match('/^[A-Za-z0-9._-]{3,80}$/', $identifier) !== 1) {
        throw new InvalidArgumentException('The API identifier must be 3 to 80 letters, numbers, dots, underscores, or hyphens.');
    }

    $oldCacheFile = discord_bots_cache_file();
    $changes = [
        'DISCORD_BOTS_API_URL' => $url,
        'DISCORD_BOTS_API_IDENTIFIER' => $identifier,
    ];
    $secret = (string) ($input['api_secret'] ?? '');
    if ($secret !== '') {
        if (strlen($secret) < 32) throw new InvalidArgumentException('The API secret must be at least 32 characters.');
        $changes['DISCORD_BOTS_API_SECRET'] = $secret;
    }
    project_settings_write_environment($changes);
    @unlink($oldCacheFile);
    @unlink(discord_bots_cache_file());
}

function discord_bots_signed_headers(string $method, string $path, string $body = ''): array
{
    $settings = discord_bots_remote_settings();
    $secret = trim((string) (getenv('DISCORD_BOTS_API_SECRET') ?: ''));
    if ($settings['identifier'] === '' || $secret === '') throw new RuntimeException('Professor API authentication is not configured.');

    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $dailySecret = hash_hmac('sha256', $settings['identifier'] . '-' . gmdate('Y-m-d'), $secret);
    $canonicalRequest = implode("\n", [
        strtoupper($method),
        $path,
        $timestamp,
        $nonce,
        hash('sha256', $body),
    ]);
    $signature = hash_hmac('sha256', $canonicalRequest, $dailySecret);

    return [
        'Accept: application/json',
        'X-Evox-Identifier: ' . $settings['identifier'],
        'X-Evox-Timestamp: ' . $timestamp,
        'X-Evox-Nonce: ' . $nonce,
        'X-Evox-Signature: ' . $signature,
    ];
}

function discord_bots_remote_request(): array
{
    if (!discord_bots_remote_configured()) throw new RuntimeException('Configure the Professor management API in project settings.');
    if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is unavailable.');

    $url = discord_bots_remote_settings()['url'] . '/api/dashboard/overview';
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/api/dashboard/overview');
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => discord_bots_signed_headers('GET', $path),
        CURLOPT_USERAGENT => 'AWarnerDashboard/1.0',
    ]);
    $started = microtime(true);
    $body = curl_exec($handle);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    $responseMilliseconds = (int) round((microtime(true) - $started) * 1000);
    curl_close($handle);

    if ($body === false) throw new RuntimeException($error !== '' ? $error : 'Professor API request failed.');
    if ($statusCode !== 200) throw new RuntimeException('Professor API returned HTTP ' . $statusCode . '.');
    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload['stats'] ?? null) || !is_array($payload['services'] ?? null)) {
        throw new RuntimeException('Professor API returned an invalid response.');
    }

    return discord_bots_normalize($payload, $responseMilliseconds);
}

function discord_bots_normalize(array $payload, int $responseMilliseconds): array
{
    $services = [];
    foreach ($payload['services'] as $service) {
        if (!is_array($service)) continue;
        $remoteStatus = strtolower((string) ($service['status'] ?? 'unknown'));
        $status = $remoteStatus === 'online' ? 'healthy' : ($remoteStatus === 'offline' ? 'error' : 'warning');
        $details = [];
        if (!empty($service['last_seen'])) $details['Last seen'] = (string) $service['last_seen'];
        if (!empty($service['started_at'])) $details['Started'] = (string) $service['started_at'];
        $services[] = [
            'key' => (string) ($service['key'] ?? ''),
            'name' => (string) ($service['name'] ?? 'Unknown service'),
            'status' => $status,
            'message' => (string) ($service['role'] ?? 'Evolution X Discord service'),
            'details' => $details,
        ];
    }

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
        'checked_at' => (string) ($payload['generated_at'] ?? gmdate(DATE_ATOM)),
        'response_ms' => $responseMilliseconds,
        'stats' => [
            'total_users' => (int) ($payload['stats']['total_users'] ?? 0),
            'total_applications' => (int) ($payload['stats']['total_applications'] ?? 0),
            'pending_applications' => (int) ($payload['stats']['pending_applications'] ?? 0),
            'open_abuse_records' => (int) ($payload['stats']['open_abuse_records'] ?? 0),
        ],
        'services' => $services,
        'stale' => false,
        'error' => null,
    ];
}

function discord_bots_overview(bool $forceRefresh = false): array
{
    $cacheFile = discord_bots_cache_file();
    $cached = is_readable($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    if (!$forceRefresh && is_array($cached) && (int) ($cached['cached_at'] ?? 0) >= time() - 30 && is_array($cached['data'] ?? null)) {
        return $cached['data'];
    }

    try {
        $data = discord_bots_remote_request();
        dashboard_cache_write($cacheFile, ['cached_at' => time(), 'data' => $data]);
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
            'stats' => array_fill_keys(['total_users', 'total_applications', 'pending_applications', 'open_abuse_records'], 0),
            'services' => [],
            'stale' => false,
            'error' => $error->getMessage(),
        ];
    }
}