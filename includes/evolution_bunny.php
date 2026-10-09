<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';

function evolution_bunny_setting(string $key, string $default = ''): string
{
    $value = getenv('EVOLUTION_CDN_BUNNY_' . $key);
    return $value === false ? $default : trim((string) $value);
}

function evolution_bunny_settings(): array
{
    return [
        'zone' => evolution_bunny_setting('STORAGE_ZONE'),
        'host' => evolution_bunny_setting('STORAGE_HOST', 'storage.bunnycdn.com'),
        'region' => evolution_bunny_setting('STORAGE_REGION', 'de'),
        'cdn_host' => evolution_bunny_setting('CDN_HOST'),
        'has_access_key' => evolution_bunny_setting('STORAGE_ACCESS_KEY') !== '',
    ];
}

function evolution_bunny_configured(): bool
{
    $settings = evolution_bunny_settings();
    return $settings['zone'] !== '' && $settings['has_access_key'];
}

function evolution_bunny_save_settings(array $input): void
{
    if (!updates_writes_enabled()) throw new RuntimeException('Bunny Storage changes are disabled.');
    $zone = trim((string) ($input['storage_zone'] ?? ''));
    $host = strtolower(trim((string) ($input['storage_host'] ?? 'storage.bunnycdn.com')));
    $region = strtolower(trim((string) ($input['storage_region'] ?? 'de')));
    $cdnHost = strtolower(trim((string) ($input['cdn_host'] ?? '')));
    if ($zone === '' || preg_match('/^[A-Za-z0-9._-]+$/', $zone) !== 1) throw new InvalidArgumentException('Enter a valid Bunny Storage Zone name.');
    if (preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1) throw new InvalidArgumentException('Enter a valid Bunny Storage hostname.');
    if (preg_match('/^[a-z0-9-]+$/', $region) !== 1) throw new InvalidArgumentException('Enter a valid Bunny region.');
    if ($cdnHost !== '' && preg_match('/^[A-Za-z0-9.-]+$/', $cdnHost) !== 1) throw new InvalidArgumentException('Enter a valid CDN hostname without a path.');

    $changes = [
        'EVOLUTION_CDN_BUNNY_STORAGE_ZONE' => $zone,
        'EVOLUTION_CDN_BUNNY_STORAGE_HOST' => $host,
        'EVOLUTION_CDN_BUNNY_STORAGE_REGION' => $region,
        'EVOLUTION_CDN_BUNNY_CDN_HOST' => $cdnHost,
    ];
    $accessKey = trim((string) ($input['storage_access_key'] ?? ''));
    if ($accessKey !== '') $changes['EVOLUTION_CDN_BUNNY_STORAGE_ACCESS_KEY'] = $accessKey;
    project_settings_write_environment($changes);
}

function evolution_bunny_path(string $path, bool $allowRoot = true): string
{
    $path = trim(str_replace('\\', '/', $path), '/');
    if ($path === '') {
        if ($allowRoot) return '';
        throw new InvalidArgumentException('Select a Bunny object.');
    }
    if (strlen($path) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) throw new InvalidArgumentException('The Bunny object path is invalid.');
    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') throw new InvalidArgumentException('The Bunny object path is invalid.');
    }
    return implode('/', $segments);
}

function evolution_bunny_api_url(string $path = '', bool $directory = false): string
{
    $settings = evolution_bunny_settings();
    if (!evolution_bunny_configured()) throw new RuntimeException('Configure the Evolution X Bunny Storage Zone and access key first.');
    $path = evolution_bunny_path($path);
    $encoded = $path === '' ? '' : implode('/', array_map('rawurlencode', explode('/', $path)));
    $url = 'https://' . $settings['host'] . '/' . rawurlencode($settings['zone']) . '/' . $encoded;
    return $directory && !str_ends_with($url, '/') ? $url . '/' : $url;
}

function evolution_bunny_request(string $method, string $path = '', mixed $body = null, ?int $bodySize = null, bool $directory = false): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is required for Bunny Storage.');
    $handle = curl_init(evolution_bunny_api_url($path, $directory));
    if ($handle === false) throw new RuntimeException('The Bunny Storage request could not be initialized.');
    $headers = ['AccessKey: ' . evolution_bunny_setting('STORAGE_ACCESS_KEY')];
    $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 300];
    if (is_resource($body)) {
        $options[CURLOPT_UPLOAD] = true;
        $options[CURLOPT_INFILE] = $body;
        $options[CURLOPT_INFILESIZE] = $bodySize ?? 0;
        $headers[] = 'Content-Type: application/octet-stream';
        $options[CURLOPT_HTTPHEADER] = $headers;
    } elseif (is_string($body)) {
        $options[CURLOPT_POSTFIELDS] = $body;
        $headers[] = 'Content-Type: application/octet-stream';
        $options[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($handle, $options);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($response === false || $status < 200 || $status >= 300) {
        $detail = $error !== '' ? $error : 'HTTP ' . $status;
        throw new RuntimeException('Bunny Storage request failed: ' . $detail . '.');
    }
    return ['status' => $status, 'body' => (string) $response];
}

function evolution_bunny_list(string $path = ''): array
{
    $path = evolution_bunny_path($path);
    $response = evolution_bunny_request('GET', $path, null, null, true);
    $decoded = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException('Bunny Storage returned an invalid directory listing.');
    $items = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry) || trim((string) ($entry['ObjectName'] ?? '')) === '') continue;
        $name = (string) $entry['ObjectName'];
        $items[] = [
            'name' => $name,
            'path' => ($path === '' ? '' : $path . '/') . $name,
            'is_directory' => !empty($entry['IsDirectory']),
            'size' => (int) ($entry['Length'] ?? 0),
            'modified_at' => (string) ($entry['LastChanged'] ?? $entry['DateCreated'] ?? ''),
            'checksum' => (string) ($entry['Checksum'] ?? ''),
        ];
    }
    usort($items, static fn(array $left, array $right): int => $left['is_directory'] === $right['is_directory']
        ? strnatcasecmp($left['name'], $right['name'])
        : ($left['is_directory'] ? -1 : 1));
    return $items;
}

function evolution_bunny_upload(array $upload, string $directory): string
{
    if (!updates_writes_enabled()) throw new RuntimeException('Bunny Storage changes are disabled.');
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) throw new InvalidArgumentException('Select a file to upload.');
    $size = (int) ($upload['size'] ?? 0);
    if ($size < 1 || $size > 536870912) throw new InvalidArgumentException('Uploads must be between 1 byte and 512 MB.');
    $name = basename(str_replace('\\', '/', trim((string) ($upload['name'] ?? ''))));
    $path = evolution_bunny_path(($directory === '' ? '' : evolution_bunny_path($directory) . '/') . $name, false);
    $stream = fopen((string) $upload['tmp_name'], 'rb');
    if ($stream === false) throw new RuntimeException('The uploaded file could not be opened.');
    try {
        evolution_bunny_request('PUT', $path, $stream, $size);
    } finally {
        fclose($stream);
    }
    return $path;
}

function evolution_bunny_create_directory(string $parent, string $name): string
{
    if (!updates_writes_enabled()) throw new RuntimeException('Bunny Storage changes are disabled.');
    $name = trim($name);
    $path = evolution_bunny_path(($parent === '' ? '' : evolution_bunny_path($parent) . '/') . $name, false);
    evolution_bunny_request('PUT', $path, '', null, true);
    return $path;
}

function evolution_bunny_delete(string $path): void
{
    if (!updates_writes_enabled()) throw new RuntimeException('Bunny Storage changes are disabled.');
    evolution_bunny_request('DELETE', evolution_bunny_path($path, false));
}

function evolution_bunny_download(string $path): never
{
    $path = evolution_bunny_path($path, false);
    if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is required for Bunny Storage.');
    $handle = curl_init(evolution_bunny_api_url($path));
    if ($handle === false) throw new RuntimeException('The Bunny download could not be initialized.');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addcslashes(basename($path), "\"\\") . '"');
    header('Cache-Control: private, no-store');
    curl_setopt_array($handle, [
        CURLOPT_HTTPHEADER => ['AccessKey: ' . evolution_bunny_setting('STORAGE_ACCESS_KEY')],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $data): int { echo $data; return strlen($data); },
    ]);
    $success = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($success === false || $status !== 200) throw new RuntimeException('The Bunny object could not be downloaded.');
    exit;
}

function evolution_bunny_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1073741824, 2) . ' GB';
}