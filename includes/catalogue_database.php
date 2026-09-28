<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';
require_once __DIR__ . '/bunny_storage.php';

function catalogue_database_tables(): array
{
    return [
        'catalogue' => project_database_table('tileimagegen', 'catalogue'),
        'assets' => project_database_table('tileimagegen', 'catalogue_assets'),
    ];
}

function catalogue_database(bool $ensureSchema = true): PDO
{
    $database = project_database_connection('tileimagegen');
    if (!$ensureSchema) {
        return $database;
    }
    $tables = catalogue_database_tables();
    $database->exec("CREATE TABLE IF NOT EXISTS {$tables['catalogue']} (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        document LONGTEXT NOT NULL,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $database->exec("CREATE TABLE IF NOT EXISTS {$tables['assets']} (
        asset_id CHAR(64) NOT NULL PRIMARY KEY,
        object_key VARCHAR(700) NOT NULL,
        cdn_url VARCHAR(1000) NULL,
        byte_size BIGINT UNSIGNED NULL,
        sha256 CHAR(64) NULL,
        storage_state VARCHAR(20) NOT NULL DEFAULT 'pending',
        last_error VARCHAR(500) NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY catalogue_assets_object_key (object_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    return $database;
}

function catalogue_database_load(): ?array
{
    try {
        $database = catalogue_database(false);
        $table = catalogue_database_tables()['catalogue'];
        $row = $database->query("SELECT document FROM {$table} WHERE id = 1")->fetch();
        if (!is_array($row)) {
            return null;
        }

        $catalogue = json_decode((string) $row['document'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($catalogue) || !is_array($catalogue['brands'] ?? null)) {
            throw new RuntimeException('The database catalogue is invalid.');
        }
        return $catalogue;
    } catch (JsonException $exception) {
        throw new RuntimeException('The database catalogue contains invalid JSON.', 0, $exception);
    }
}

function catalogue_database_snapshot(): ?array
{
    $database = catalogue_database(false);
    $table = catalogue_database_tables()['catalogue'];
    $row = $database->query("SELECT document, revision, updated_at FROM {$table} WHERE id = 1")->fetch();
    if (!is_array($row)) {
        return null;
    }

    $catalogue = json_decode((string) $row['document'], true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($catalogue) || !is_array($catalogue['brands'] ?? null)) {
        throw new RuntimeException('The database catalogue is invalid.');
    }
    return [
        'catalogue' => $catalogue,
        'revision' => (int) $row['revision'],
        'updated_at' => (string) $row['updated_at'],
    ];
}

function catalogue_database_storage_status(): array
{
    $database = catalogue_database(false);
    $table = catalogue_database_tables()['assets'];
    $rows = $database->query("SELECT storage_state, COUNT(*) AS total, COALESCE(SUM(byte_size), 0) AS bytes,
        MAX(updated_at) AS updated_at FROM {$table} GROUP BY storage_state")->fetchAll();
    $counts = ['pending' => 0, 'ready' => 0, 'error' => 0, 'delete_pending' => 0, 'deleted' => 0];
    $readyBytes = 0;
    $updatedAt = null;
    foreach ($rows as $row) {
        $state = (string) $row['storage_state'];
        $counts[$state] = (int) $row['total'];
        if ($state === 'ready') {
            $readyBytes = (int) $row['bytes'];
        }
        if ($row['updated_at'] !== null && ($updatedAt === null || (string) $row['updated_at'] > $updatedAt)) {
            $updatedAt = (string) $row['updated_at'];
        }
    }
    $total = $counts['ready'] + $counts['pending'] + $counts['error'];

    return [
        'total' => $total,
        'ready' => $counts['ready'],
        'pending' => $counts['pending'],
        'errors' => $counts['error'],
        'deleted' => $counts['deleted'],
        'ready_bytes' => $readyBytes,
        'percent' => $total > 0 ? round(($counts['ready'] / $total) * 100, 1) : 0.0,
        'updated_at' => $updatedAt,
    ];
}

function catalogue_database_image_keys(array $catalogue): array
{
    $keys = [];
    foreach (($catalogue['brands'] ?? []) as $brand) {
        foreach (($brand['ranges'] ?? []) as $range) {
            foreach (($range['versions'] ?? []) as $version) {
                foreach (($version['sizes'] ?? []) as $size) {
                    foreach (($size['images'] ?? []) as $objectKey) {
                        if (is_string($objectKey)) {
                            $keys[] = bunny_storage_object_key($objectKey);
                        }
                    }
                }
            }
        }
    }
    return array_values(array_unique($keys));
}

function catalogue_database_save(array $catalogue): void
{
    $database = catalogue_database();
    $tables = catalogue_database_tables();
    $document = json_encode($catalogue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $objectKeys = catalogue_database_image_keys($catalogue);
    $cdnConfigured = bunny_storage_setting('BUNNY_CDN_HOST') !== '';

    $database->beginTransaction();
    try {
        $statement = $database->prepare("INSERT INTO {$tables['catalogue']} (id, document, revision, updated_at)
            VALUES (1, :document, 1, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE document = VALUES(document), revision = revision + 1, updated_at = VALUES(updated_at)");
        $statement->execute([':document' => $document]);

        $assetStatement = $database->prepare("INSERT INTO {$tables['assets']}
            (asset_id, object_key, cdn_url, storage_state, updated_at)
            VALUES (:asset_id, :object_key, :cdn_url, 'pending', UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE cdn_url = VALUES(cdn_url),
                storage_state = IF(storage_state = 'ready', 'ready', 'pending'), updated_at = VALUES(updated_at)");
        foreach ($objectKeys as $objectKey) {
            $assetStatement->execute([
                ':asset_id' => hash('sha256', $objectKey),
                ':object_key' => $objectKey,
                ':cdn_url' => $cdnConfigured ? bunny_storage_url($objectKey) : null,
            ]);
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function catalogue_database_mark_asset(string $objectKey, string $state, ?int $byteSize = null, ?string $sha256 = null, ?string $error = null): void
{
    if (!in_array($state, ['pending', 'ready', 'delete_pending', 'deleted', 'error'], true)) {
        throw new InvalidArgumentException('The catalogue asset state is invalid.');
    }
    $objectKey = bunny_storage_object_key($objectKey);
    $database = catalogue_database();
    $table = catalogue_database_tables()['assets'];
    $statement = $database->prepare("INSERT INTO {$table}
        (asset_id, object_key, cdn_url, byte_size, sha256, storage_state, last_error, updated_at)
        VALUES (:asset_id, :object_key, :cdn_url, :byte_size, :sha256, :storage_state, :last_error, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE cdn_url = VALUES(cdn_url), byte_size = COALESCE(VALUES(byte_size), byte_size),
            sha256 = COALESCE(VALUES(sha256), sha256), storage_state = VALUES(storage_state),
            last_error = VALUES(last_error), updated_at = VALUES(updated_at)");
    $statement->execute([
        ':asset_id' => hash('sha256', $objectKey),
        ':object_key' => $objectKey,
        ':cdn_url' => bunny_storage_setting('BUNNY_CDN_HOST') !== '' ? bunny_storage_url($objectKey) : null,
        ':byte_size' => $byteSize,
        ':sha256' => $sha256,
        ':storage_state' => $state,
        ':last_error' => $error === null ? null : mb_substr($error, 0, 500),
    ]);
}

function catalogue_database_assets(): array
{
    $database = catalogue_database(false);
    $table = catalogue_database_tables()['assets'];
    return $database->query("SELECT object_key, byte_size, sha256, storage_state, last_error FROM {$table} ORDER BY object_key")->fetchAll();
}
