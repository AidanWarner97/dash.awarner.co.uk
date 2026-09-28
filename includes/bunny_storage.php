<?php

declare(strict_types=1);

function bunny_storage_setting(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : trim((string) $value);
}

function bunny_storage_enabled(): bool
{
    return bunny_storage_setting('BUNNY_STORAGE_ZONE') !== ''
        && bunny_storage_setting('BUNNY_STORAGE_ACCESS_KEY') !== ''
        && bunny_storage_setting('BUNNY_CDN_HOST') !== '';
}

function bunny_storage_object_key(string $objectKey): string
{
    $objectKey = ltrim(str_replace('\\', '/', trim($objectKey)), '/');
    if (!str_starts_with($objectKey, 'catalogue/images/') || str_contains($objectKey, '..')) {
        throw new InvalidArgumentException('The catalogue image object key is invalid.');
    }

    foreach (explode('/', $objectKey) as $segment) {
        if ($segment === '' || preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1) {
            throw new InvalidArgumentException('The catalogue image object key contains unsupported characters.');
        }
    }

    return $objectKey;
}

function bunny_storage_url(string $objectKey): string
{
    $host = preg_replace('#^https?://#i', '', bunny_storage_setting('BUNNY_CDN_HOST'));
    if ($host === null || $host === '' || str_contains($host, '/')) {
        throw new RuntimeException('BUNNY_CDN_HOST must contain a hostname without a path.');
    }

    return 'https://' . $host . '/' . implode('/', array_map('rawurlencode', explode('/', bunny_storage_object_key($objectKey))));
}

function bunny_storage_api_url(string $objectKey): string
{
    $zone = bunny_storage_setting('BUNNY_STORAGE_ZONE');
    if (preg_match('/^[A-Za-z0-9._-]+$/', $zone) !== 1) {
        throw new RuntimeException('The Bunny Storage host or zone is invalid.');
    }

    $configuredHost = bunny_storage_setting('BUNNY_STORAGE_HOST', 'storage.bunnycdn.com');
    $endpoint = preg_match('#^https?://#i', $configuredHost) === 1 ? $configuredHost : 'https://' . $configuredHost;
    $parts = parse_url($endpoint);
    $path = trim((string) ($parts['path'] ?? ''), '/');
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || preg_match('/^[A-Za-z0-9.-]+$/', (string) ($parts['host'] ?? '')) !== 1
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || isset($parts['query']) || isset($parts['fragment']) || ($path !== '' && $path !== $zone)) {
        throw new RuntimeException('BUNNY_STORAGE_HOST must be a Bunny hostname or its full Storage Zone endpoint.');
    }
    $host = (string) $parts['host'];

    return 'https://' . $host . '/' . rawurlencode($zone) . '/'
        . implode('/', array_map('rawurlencode', explode('/', bunny_storage_object_key($objectKey))));
}

function bunny_storage_request(string $method, string $objectKey, mixed $body = null, ?int $bodySize = null): void
{
    if (!bunny_storage_enabled()) {
        throw new RuntimeException('Bunny Storage is not configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Bunny Storage.');
    }

    $handle = curl_init(bunny_storage_api_url($objectKey));
    if ($handle === false) {
        throw new RuntimeException('A Bunny Storage request could not be initialized.');
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'AccessKey: ' . bunny_storage_setting('BUNNY_STORAGE_ACCESS_KEY'),
            'Content-Type: application/octet-stream',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 300,
    ];
    if (is_resource($body)) {
        $options[CURLOPT_UPLOAD] = true;
        $options[CURLOPT_INFILE] = $body;
        $options[CURLOPT_INFILESIZE] = $bodySize ?? 0;
    }
    curl_setopt_array($handle, $options);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    $accepted = $method === 'DELETE' ? [200, 404] : [200, 201];
    if ($response === false || !in_array($status, $accepted, true)) {
        $detail = $error !== '' ? $error : 'HTTP ' . $status;
        throw new RuntimeException('Bunny Storage request failed: ' . $detail . '.');
    }
}

function bunny_storage_upload(string $sourcePath, string $objectKey): void
{
    $size = is_file($sourcePath) ? filesize($sourcePath) : false;
    $stream = is_readable($sourcePath) ? fopen($sourcePath, 'rb') : false;
    if ($size === false || $stream === false) {
        throw new RuntimeException('The catalogue image could not be opened for upload.');
    }

    try {
        bunny_storage_request('PUT', $objectKey, $stream, $size);
    } finally {
        fclose($stream);
    }
}

function bunny_storage_delete(string $objectKey): void
{
    bunny_storage_request('DELETE', $objectKey);
}

function bunny_storage_remote_hash(string $objectKey): array
{
    if (!bunny_storage_enabled() || !function_exists('curl_init')) {
        throw new RuntimeException('Bunny Storage and the PHP cURL extension are required for verification.');
    }

    $hash = hash_init('sha256');
    $bytes = 0;
    $handle = curl_init(bunny_storage_api_url($objectKey));
    if ($handle === false) {
        throw new RuntimeException('A Bunny Storage verification request could not be initialized.');
    }
    curl_setopt_array($handle, [
        CURLOPT_HTTPHEADER => ['AccessKey: ' . bunny_storage_setting('BUNNY_STORAGE_ACCESS_KEY')],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($hash, &$bytes): int {
            $bytes += strlen($data);
            hash_update($hash, $data);
            return strlen($data);
        },
    ]);
    $success = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($success === false || $status !== 200) {
        $detail = $error !== '' ? $error : 'HTTP ' . $status;
        throw new RuntimeException('Bunny Storage verification failed: ' . $detail . '.');
    }

    return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
}
