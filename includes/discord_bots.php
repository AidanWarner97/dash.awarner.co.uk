<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';
require_once __DIR__ . '/auth.php';

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

function discord_bots_features_cache_file(): string
{
    return str_replace('.json', '-features.json', discord_bots_cache_file());
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
    $actor = (string) (auth_current_user()['discord_user_id'] ?? '');
    $dailySecret = hash_hmac('sha256', $settings['identifier'] . '-' . gmdate('Y-m-d'), $secret);
    $canonicalRequest = implode("\n", [
        strtoupper($method),
        $path,
        $timestamp,
        $nonce,
        $actor,
        hash('sha256', $body),
    ]);
    $signature = hash_hmac('sha256', $canonicalRequest, $dailySecret);

    return [
        'Accept: application/json',
        'X-Evox-Identifier: ' . $settings['identifier'],
        'X-Evox-Timestamp: ' . $timestamp,
        'X-Evox-Nonce: ' . $nonce,
        'X-Evox-Actor: ' . $actor,
        'X-Evox-Signature: ' . $signature,
    ];
}

function discord_bots_api_get(string $endpoint): array
{
    return discord_bots_management_request('GET', $endpoint, null, false);
}

function discord_bots_management_request(string $method, string $endpoint, ?array $payload = null, bool $requireActor = true): array
{
    if (!discord_bots_remote_configured()) throw new RuntimeException('Configure the Professor management API in project settings.');
    if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is unavailable.');
    $method = strtoupper($method);
    $path = (string) (parse_url($endpoint, PHP_URL_PATH) ?: '');
    $allowed = [
        'GET' => ['#^/api/dashboard/(overview|features)$#', '#^/api/(applications|applicants|users|abuse|questions|logs|cache|privacy-requests)$#', '#^/api/applicants/[0-9]+$#', '#^/api/users/[0-9]+$#', '#^/api/users/[0-9]+/action-permissions$#', '#^/api/group-action-permissions/[0-3]$#', '#^/api/action-permission-templates$#'],
        'POST' => ['#^/api/questions$#', '#^/api/action-permission-templates$#', '#^/api/abuse/[0-9]+/ignore$#', '#^/api/applicants/[0-9]+/override-reapply$#', '#^/api/cache/refresh/(maintainers|devices|donations|all)$#'],
        'PUT' => ['#^/api/users/[0-9]+$#', '#^/api/users/[0-9]+/action-permissions$#', '#^/api/group-action-permissions/[0-3]$#', '#^/api/privacy-requests/[A-Za-z0-9-]+$#'],
        'DELETE' => ['#^/api/users/[0-9]+$#', '#^/api/cache/[A-Za-z0-9._/-]+$#'],
    ];
    if (!isset($allowed[$method]) || !array_filter($allowed[$method], static fn(string $pattern): bool => preg_match($pattern, $path) === 1)) throw new InvalidArgumentException('Unsupported Professor API operation.');
    if ($requireActor && trim((string) (auth_current_user()['discord_user_id'] ?? '')) === '') throw new RuntimeException('Link your Discord user ID in Account settings before using bot management.');

    $url = discord_bots_remote_settings()['url'] . $endpoint;
    $signedPath = (string) (parse_url($url, PHP_URL_PATH) ?: $path);
    $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $handle = curl_init($url);
    $headers = discord_bots_signed_headers($method, $signedPath, $body);
    if ($body !== '') $headers[] = 'Content-Type: application/json';
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body !== '' ? $body : null,
        CURLOPT_HTTPHEADER => $headers,
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

    return ['payload' => $payload, 'response_ms' => $responseMilliseconds, 'status_code' => $statusCode];
}

function discord_bots_remote_request(): array
{
    $response = discord_bots_api_get('/api/dashboard/overview');
    $payload = $response['payload'];
    if (!is_array($payload['stats'] ?? null) || !is_array($payload['services'] ?? null)) throw new RuntimeException('Professor API returned an invalid response.');

    return discord_bots_normalize($payload, $response['response_ms']);
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

function discord_bots_default_features(): array
{
    return [
        ['key' => 'overview', 'name' => 'Service Overview', 'description' => 'Bot status, uptime, aggregate totals, and recent activity.', 'path' => '/overview', 'category' => 'Operations', 'icon' => 'chart-no-axes-column', 'mode' => 'View'],
        ['key' => 'applications', 'name' => 'Applications', 'description' => 'Review all maintainer applications and their current status.', 'path' => '/applications', 'category' => 'Applications', 'icon' => 'files', 'mode' => 'Manage'],
        ['key' => 'applicants', 'name' => 'Applicants', 'description' => 'Inspect applicant history, eligibility, and reapply state.', 'path' => '/applicants', 'category' => 'Applications', 'icon' => 'user-round-search', 'mode' => 'Manage'],
        ['key' => 'questions', 'name' => 'Application Questions', 'description' => 'Create, reorder, validate, and remove application questions.', 'path' => '/questions', 'category' => 'Applications', 'icon' => 'list-checks', 'mode' => 'Manage'],
        ['key' => 'users', 'name' => 'Users', 'description' => 'Browse Discord users, access level, and block state.', 'path' => '/users', 'category' => 'Access Control', 'icon' => 'users', 'mode' => 'Manage'],
        ['key' => 'group-permissions', 'name' => 'Group Permissions', 'description' => 'Configure command permissions for each access level.', 'path' => '/group-permissions', 'category' => 'Access Control', 'icon' => 'shield-check', 'mode' => 'Manage'],
        ['key' => 'abuse', 'name' => 'Abuse Logs', 'description' => 'Review and resolve recorded abuse and policy violations.', 'path' => '/abuse', 'category' => 'Access Control', 'icon' => 'shield-alert', 'mode' => 'Manage'],
        ['key' => 'cache', 'name' => 'Cache Management', 'description' => 'Inspect, clear, and refresh bot data caches.', 'path' => '/cache', 'category' => 'Operations', 'icon' => 'database-zap', 'mode' => 'Manage'],
        ['key' => 'privacy-requests', 'name' => 'Privacy Requests', 'description' => 'Process privacy requests and record staff outcomes.', 'path' => '/privacy-requests', 'category' => 'Operations', 'icon' => 'user-round-check', 'mode' => 'Manage'],
        ['key' => 'action-logs', 'name' => 'User Action Logs', 'description' => 'Audit recorded user and staff actions.', 'path' => '/logs-actions', 'category' => 'Logs', 'icon' => 'list-restart', 'mode' => 'View'],
        ['key' => 'system-logs', 'name' => 'System Logs', 'description' => 'Inspect Bender, Scruffy, and Professor service logs.', 'path' => '/logs-system', 'category' => 'Logs', 'icon' => 'scroll-text', 'mode' => 'View'],
    ];
}

function discord_bots_normalize_features(array $features): array
{
    $normalized = [];
    foreach ($features as $feature) {
        if (!is_array($feature)) continue;
        $path = (string) ($feature['path'] ?? '');
        $icon = (string) ($feature['icon'] ?? 'circle-dot');
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) continue;
        if (preg_match('/^[a-z0-9-]+$/', $icon) !== 1) $icon = 'circle-dot';
        $normalized[] = [
            'key' => (string) ($feature['key'] ?? ''),
            'name' => (string) ($feature['name'] ?? 'Unknown feature'),
            'description' => (string) ($feature['description'] ?? ''),
            'path' => $path,
            'category' => (string) ($feature['category'] ?? 'Other'),
            'icon' => $icon,
            'mode' => in_array(($feature['mode'] ?? ''), ['View', 'Manage'], true) ? $feature['mode'] : 'View',
        ];
    }
    return $normalized;
}

function discord_bots_feature_url(string $path): string
{
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) throw new InvalidArgumentException('Invalid Professor feature path.');
    $baseUrl = discord_bots_remote_settings()['url'];
    if ($baseUrl === '') $baseUrl = 'https://' . project_settings('discordbots')['domain'];
    return $baseUrl . $path;
}

function discord_bots_features(bool $forceRefresh = false): array
{
    $cacheFile = discord_bots_features_cache_file();
    $cached = is_readable($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    if (!$forceRefresh && is_array($cached) && (int) ($cached['cached_at'] ?? 0) >= time() - 300 && is_array($cached['data']['features'] ?? null)) {
        return $cached['data'];
    }

    try {
        $response = discord_bots_api_get('/api/dashboard/features');
        $features = discord_bots_normalize_features((array) ($response['payload']['features'] ?? []));
        if (!$features) throw new RuntimeException('Professor returned an empty feature catalogue.');
        $data = ['features' => $features, 'stale' => false, 'fallback' => false, 'error' => null];
        dashboard_cache_write($cacheFile, ['cached_at' => time(), 'data' => $data]);
        return $data;
    } catch (Throwable $error) {
        if (is_array($cached['data']['features'] ?? null)) {
            $cached['data']['stale'] = true;
            $cached['data']['error'] = $error->getMessage();
            return $cached['data'];
        }
        return ['features' => discord_bots_default_features(), 'stale' => false, 'fallback' => true, 'error' => $error->getMessage()];
    }
}

function discord_bots_management_data(string $feature, array $query = []): array
{
    if ($feature === 'application-detail') {
        $applicationId = filter_var($query['application_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($applicationId === false) throw new InvalidArgumentException('Invalid application ID.');
        $applications = discord_bots_management_request('GET', '/api/applications')['payload']['applications'] ?? [];
        foreach ($applications as $application) {
            if ((int) ($application['id'] ?? 0) !== $applicationId) continue;
            $questions = discord_bots_management_request('GET', '/api/questions')['payload']['questions'] ?? [];
            return ['application' => $application, 'questions' => $questions];
        }
        throw new RuntimeException('Application not found.');
    }
    if ($feature === 'user-permissions') {
        $userId = discord_bots_numeric_id((string) ($query['user_id'] ?? ''));
        return discord_bots_management_request('GET', '/api/users/' . $userId . '/action-permissions')['payload'];
    }
    if ($feature === 'applicant-detail') {
        $userId = discord_bots_numeric_id((string) ($query['user_id'] ?? ''));
        return discord_bots_management_request('GET', '/api/applicants/' . $userId)['payload'];
    }
    $endpoints = [
        'applications' => '/api/applications',
        'applicants' => '/api/applicants',
        'questions' => '/api/questions',
        'users' => '/api/users',
        'group-permissions' => '/api/group-action-permissions/' . max(0, min(3, (int) ($query['level'] ?? 3))),
        'abuse' => '/api/abuse',
        'cache' => '/api/cache',
        'privacy-requests' => '/api/privacy-requests',
        'action-logs' => '/api/logs?page=1&limit=100',
        'system-logs' => '/api/logs?page=1&limit=100',
        'permission-templates' => '/api/action-permission-templates',
    ];
    if (!isset($endpoints[$feature])) throw new InvalidArgumentException('Unknown Professor management feature.');
    return discord_bots_management_request('GET', $endpoints[$feature])['payload'];
}

function discord_bots_management_action(string $action, array $input): string
{
    if (!updates_writes_enabled()) throw new RuntimeException('Discord Bots changes are disabled.');
    switch ($action) {
        case 'save_questions':
            $questionKeys = array_values((array) ($input['question_key'] ?? []));
            $questionTexts = array_values((array) ($input['question_text'] ?? []));
            if (count($questionKeys) !== count($questionTexts) || !$questionKeys) throw new InvalidArgumentException('Add at least one complete question.');
            $questions = [];
            foreach ($questionKeys as $index => $questionKey) {
                $questionKey = trim((string) $questionKey);
                $questionText = trim((string) $questionTexts[$index]);
                if ($questionKey === '' || preg_match('/^[A-Za-z0-9_]+$/', $questionKey) !== 1) throw new InvalidArgumentException('Question keys may only contain letters, numbers, and underscores.');
                if ($questionText === '' || mb_strlen($questionText) > 500) throw new InvalidArgumentException('Each question must contain between 1 and 500 characters.');
                $questions[] = ['key' => $questionKey, 'question' => $questionText];
            }
            if (count(array_unique(array_column($questions, 'key'))) !== count($questions)) throw new InvalidArgumentException('Question keys must be unique.');
            discord_bots_management_request('POST', '/api/questions', ['questions' => $questions]);
            return 'Application questions saved.';
        case 'update_user':
            $userId = discord_bots_numeric_id((string) ($input['user_id'] ?? ''));
            discord_bots_management_request('PUT', '/api/users/' . $userId, [
                'permission_level' => max(0, min(3, (int) ($input['permission_level'] ?? 0))),
                'is_blocked' => isset($input['is_blocked']),
                'blocked_until' => trim((string) ($input['blocked_until'] ?? '')) ?: null,
            ]);
            return 'User access updated.';
        case 'delete_user':
            discord_bots_management_request('DELETE', '/api/users/' . discord_bots_numeric_id((string) ($input['user_id'] ?? '')));
            return 'User deleted.';
        case 'save_group_permissions':
            $level = max(0, min(3, (int) ($input['permission_level'] ?? 3)));
            $current = discord_bots_management_request('GET', '/api/group-action-permissions/' . $level)['payload']['actions'] ?? [];
            $allowed = array_map('strval', (array) ($input['allowed_actions'] ?? []));
            $actions = array_map(static fn(array $item): array => ['action' => (string) $item['action'], 'allowed' => in_array((string) $item['action'], $allowed, true)], $current);
            discord_bots_management_request('PUT', '/api/group-action-permissions/' . $level, ['actions' => $actions]);
            return 'Group permissions saved.';
        case 'save_user_permissions':
            $userId = discord_bots_numeric_id((string) ($input['user_id'] ?? ''));
            $current = discord_bots_management_request('GET', '/api/users/' . $userId . '/action-permissions')['payload']['actions'] ?? [];
            $allowed = array_map('strval', (array) ($input['allowed_actions'] ?? []));
            $actions = array_map(static fn(array $item): array => ['action' => (string) $item['action'], 'allowed' => in_array((string) $item['action'], $allowed, true)], $current);
            discord_bots_management_request('PUT', '/api/users/' . $userId . '/action-permissions', ['actions' => $actions]);
            return 'User command permissions saved.';
        case 'save_permission_templates':
            $templates = json_decode((string) ($input['templates_json'] ?? ''), true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($templates)) throw new InvalidArgumentException('Enter a valid templates array.');
            discord_bots_management_request('POST', '/api/action-permission-templates', ['templates' => $templates]);
            return 'Permission templates saved.';
        case 'resolve_abuse':
            discord_bots_management_request('POST', '/api/abuse/' . (int) ($input['record_id'] ?? 0) . '/ignore', []);
            return 'Abuse record resolved.';
        case 'override_reapply':
            discord_bots_management_request('POST', '/api/applicants/' . discord_bots_numeric_id((string) ($input['user_id'] ?? '')) . '/override-reapply', []);
            return 'Applicant reapply cooldown overridden.';
        case 'refresh_cache':
            $type = (string) ($input['cache_type'] ?? '');
            if (!in_array($type, ['maintainers', 'devices', 'donations', 'all'], true)) throw new InvalidArgumentException('Select a valid cache type.');
            discord_bots_management_request('POST', '/api/cache/refresh/' . $type, []);
            return ucfirst($type) . ' cache refreshed.';
        case 'delete_cache':
            $key = trim((string) ($input['cache_key'] ?? ''));
            if ($key === '' || preg_match('#^[A-Za-z0-9._/-]+$#', $key) !== 1) throw new InvalidArgumentException('Invalid cache key.');
            discord_bots_management_request('DELETE', '/api/cache/' . $key);
            return 'Cache entry deleted.';
        case 'update_privacy':
            $requestId = trim((string) ($input['request_id'] ?? ''));
            if (preg_match('/^[A-Za-z0-9-]+$/', $requestId) !== 1) throw new InvalidArgumentException('Invalid privacy request.');
            discord_bots_management_request('PUT', '/api/privacy-requests/' . $requestId, ['status' => (string) ($input['status'] ?? ''), 'note' => (string) ($input['note'] ?? '')]);
            return 'Privacy request updated.';
    }
    throw new InvalidArgumentException('Unknown management action.');
}

function discord_bots_numeric_id(string $value): string
{
    $value = trim($value);
    if (preg_match('/^[0-9]{1,20}$/', $value) !== 1) throw new InvalidArgumentException('Invalid Discord user ID.');
    return $value;
}