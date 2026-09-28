<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/catalogue_manager.php';

function storage_cli_usage(): never
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php bin/catalogue-storage.php status\n");
    fwrite(STDERR, "  php bin/catalogue-storage.php migrate [--limit=N] [--force] [--no-verify]\n");
    fwrite(STDERR, "  php bin/catalogue-storage.php deletion-manifest --manifest=/secure/path/manifest.json\n");
    fwrite(STDERR, "  php bin/catalogue-storage.php delete-local --manifest=/secure/path/manifest.json --confirm\n");
    exit(2);
}

function storage_cli_options(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if ($argument === '--force' || $argument === '--no-verify' || $argument === '--confirm') {
            $options[ltrim($argument, '-')] = true;
            continue;
        }
        if (preg_match('/^--(limit|manifest)=(.+)$/', $argument, $matches) === 1) {
            $options[$matches[1]] = $matches[2];
            continue;
        }
        storage_cli_usage();
    }
    return $options;
}

function storage_cli_asset_map(): array
{
    $assets = [];
    foreach (catalogue_database_assets() as $asset) {
        $assets[(string) $asset['object_key']] = $asset;
    }
    return $assets;
}

function storage_cli_status(): void
{
    $states = [];
    $bytes = 0;
    foreach (catalogue_database_assets() as $asset) {
        $state = (string) $asset['storage_state'];
        $states[$state] = ($states[$state] ?? 0) + 1;
        if ($state === 'ready') $bytes += (int) $asset['byte_size'];
    }
    ksort($states);
    foreach ($states as $state => $count) printf("%-16s %d\n", $state, $count);
    printf("%-16s %s\n", 'verified bytes', number_format($bytes));
}

function storage_cli_migrate(array $options): void
{
    if (!bunny_storage_enabled()) {
        throw new RuntimeException('Set BUNNY_STORAGE_ZONE, BUNNY_STORAGE_ACCESS_KEY, and BUNNY_CDN_HOST before migrating.');
    }
    $limit = isset($options['limit']) ? filter_var($options['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : 0;
    if ($limit === false) throw new InvalidArgumentException('--limit must be a positive integer.');

    $catalogue = catalogue_load();
    catalogue_database_save($catalogue);
    $assets = storage_cli_asset_map();
    $root = catalogue_project_root();
    $processed = 0;
    $uploaded = 0;
    $skipped = 0;
    $failed = 0;

    foreach (catalogue_database_image_keys($catalogue) as $objectKey) {
        if ($limit > 0 && $processed >= $limit) break;
        $processed++;
        $localFile = $root . '/' . $objectKey;
        if (!is_file($localFile) || !is_readable($localFile)) {
            catalogue_database_mark_asset($objectKey, 'error', null, null, 'Local source file is unavailable.');
            fwrite(STDERR, "MISSING {$objectKey}\n");
            $failed++;
            continue;
        }
        $bytes = filesize($localFile);
        $sha256 = hash_file('sha256', $localFile);
        if ($bytes === false || $sha256 === false) {
            catalogue_database_mark_asset($objectKey, 'error', null, null, 'Local source file could not be hashed.');
            fwrite(STDERR, "HASH FAILED {$objectKey}\n");
            $failed++;
            continue;
        }
        $existing = $assets[$objectKey] ?? null;
        if (!isset($options['force']) && ($existing['storage_state'] ?? '') === 'ready'
            && (int) ($existing['byte_size'] ?? -1) === $bytes && hash_equals((string) ($existing['sha256'] ?? ''), $sha256)) {
            $skipped++;
            continue;
        }

        try {
            bunny_storage_upload($localFile, $objectKey);
            if (!isset($options['no-verify'])) {
                $remote = bunny_storage_remote_hash($objectKey);
                if ($remote['bytes'] !== $bytes || !hash_equals($sha256, $remote['sha256'])) {
                    throw new RuntimeException('Remote byte count or SHA-256 did not match the local source.');
                }
            }
            catalogue_database_mark_asset($objectKey, 'ready', $bytes, $sha256);
            printf("VERIFIED %s\n", $objectKey);
            $uploaded++;
        } catch (Throwable $exception) {
            catalogue_database_mark_asset($objectKey, 'error', $bytes, $sha256, $exception->getMessage());
            fwrite(STDERR, "FAILED {$objectKey}: {$exception->getMessage()}\n");
            $failed++;
        }
    }

    printf("Processed: %d; uploaded: %d; already verified: %d; failed: %d\n", $processed, $uploaded, $skipped, $failed);
    if ($failed > 0) exit(1);
}

function storage_cli_manifest(string $manifestPath): void
{
    if ($manifestPath === '' || is_dir($manifestPath)) throw new InvalidArgumentException('Choose a manifest file path.');
    $root = catalogue_project_root();
    $entries = [];
    foreach (catalogue_database_assets() as $asset) {
        if (($asset['storage_state'] ?? '') !== 'ready' || !is_string($asset['sha256'] ?? null)) continue;
        $objectKey = bunny_storage_object_key((string) $asset['object_key']);
        $localFile = $root . '/' . $objectKey;
        if (!is_file($localFile)) continue;
        $bytes = filesize($localFile);
        $sha256 = hash_file('sha256', $localFile);
        if ($bytes !== (int) $asset['byte_size'] || $sha256 === false || !hash_equals((string) $asset['sha256'], $sha256)) continue;
        $entries[] = ['object_key' => $objectKey, 'bytes' => $bytes, 'sha256' => $sha256];
    }
    $manifest = ['created_at' => gmdate(DATE_ATOM), 'project_root' => $root, 'files' => $entries];
    if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('The deletion manifest could not be written.');
    }
    printf("Manifest contains %d verified local files (%s bytes): %s\n", count($entries), number_format(array_sum(array_column($entries, 'bytes'))), $manifestPath);
}

function storage_cli_delete_local(string $manifestPath): void
{
    $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR) : null;
    if (!is_array($manifest) || !is_array($manifest['files'] ?? null) || ($manifest['project_root'] ?? '') !== catalogue_project_root()) {
        throw new RuntimeException('The deletion manifest is invalid or belongs to a different project root.');
    }
    $assets = storage_cli_asset_map();
    $deleted = 0;
    $bytes = 0;
    foreach ($manifest['files'] as $entry) {
        $objectKey = bunny_storage_object_key((string) ($entry['object_key'] ?? ''));
        $asset = $assets[$objectKey] ?? null;
        if (($asset['storage_state'] ?? '') !== 'ready' || !hash_equals((string) ($asset['sha256'] ?? ''), (string) ($entry['sha256'] ?? ''))) {
            throw new RuntimeException("Asset state changed after the manifest was created: {$objectKey}");
        }
        $localFile = catalogue_project_root() . '/' . $objectKey;
        if (!is_file($localFile)) continue;
        $size = filesize($localFile);
        $hash = hash_file('sha256', $localFile);
        if ($size !== (int) ($entry['bytes'] ?? -1) || $hash === false || !hash_equals((string) $entry['sha256'], $hash)) {
            throw new RuntimeException("Local file changed after the manifest was created: {$objectKey}");
        }
        if (!unlink($localFile)) throw new RuntimeException("Could not delete local file: {$objectKey}");
        $deleted++;
        $bytes += $size;
    }
    printf("Deleted %d verified local files (%s bytes).\n", $deleted, number_format($bytes));
}

$command = $argv[1] ?? '';
$options = storage_cli_options(array_slice($argv, 2));
try {
    match ($command) {
        'status' => storage_cli_status(),
        'migrate' => storage_cli_migrate($options),
        'deletion-manifest' => storage_cli_manifest((string) ($options['manifest'] ?? '')),
        'delete-local' => isset($options['confirm'])
            ? storage_cli_delete_local((string) ($options['manifest'] ?? ''))
            : throw new RuntimeException('Local deletion requires --confirm and a previously reviewed --manifest file.'),
        default => storage_cli_usage(),
    };
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
