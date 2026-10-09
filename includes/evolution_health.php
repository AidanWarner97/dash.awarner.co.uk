<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';

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

function evolution_health_monitor(bool $forceRefresh = false): array
{
    $cacheFile = evolution_health_cache_file();
    $cached = is_readable($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    if (!$forceRefresh && is_array($cached) && (int) ($cached['cached_at'] ?? 0) >= time() - 30 && is_array($cached['data'] ?? null)) {
        return $cached['data'];
    }

    try {
        if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is unavailable.');
        $domain = project_settings('evolutioncdn')['domain'];
        $handle = curl_init('https://' . $domain . '/api/health');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'EvolutionDashboardHealth/1.0',
        ]);
        $started = microtime(true);
        $response = curl_exec($handle);
        $responseMilliseconds = (int) round((microtime(true) - $started) * 1000);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);
        if ($response === false || $statusCode !== 200) {
            throw new RuntimeException($curlError !== '' ? $curlError : 'Health endpoint returned HTTP ' . $statusCode . '.');
        }
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        $data = evolution_health_normalize($decoded, $responseMilliseconds);
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
