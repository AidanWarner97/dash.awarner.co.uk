<?php

declare(strict_types=1);

function load_dashboard_environment(string $file): void
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }

    $values = parse_ini_file($file, false, INI_SCANNER_RAW);
    if (!is_array($values)) {
        return;
    }

    foreach ($values as $key => $value) {
        if (getenv((string) $key) === false) {
            putenv((string) $key . '=' . (string) $value);
        }
    }
}

load_dashboard_environment(dirname(__DIR__) . '/.env');

function dashboard_cache_directory(): string
{
    $configured = trim((string) (getenv('DASHBOARD_CACHE_DIR') ?: ''));
    $directory = $configured !== '' ? $configured : sys_get_temp_dir();
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The dashboard cache directory could not be created.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('The dashboard cache directory is not writable.');
    }
    return rtrim($directory, DIRECTORY_SEPARATOR);
}

function dashboard_cache_write(string $file, array $payload): void
{
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, $encoded, LOCK_EX) === false || !rename($temporary, $file)) {
        @unlink($temporary);
        throw new RuntimeException('The dashboard cache could not be written.');
    }
}

function tileimagegen_root(): ?string
{
    $root = trim((string) (getenv('TILEIMAGEGEN_ROOT') ?: ''));
    if ($root === '') {
        return null;
    }

    $resolved = realpath($root);
    return $resolved !== false && is_dir($resolved) ? $resolved : null;
}

function tileimagegen_database(): ?PDO
{
    $dsn = trim((string) (getenv('TILEIMAGEGEN_DB_DSN') ?: ''));
    if ($dsn === '') {
        return null;
    }

    $database = new PDO(
        $dsn,
        (string) (getenv('TILEIMAGEGEN_DB_USER') ?: ''),
        (string) (getenv('TILEIMAGEGEN_DB_PASSWORD') ?: ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The Tile Image Generator connection must use MariaDB/MySQL.');
    }

    if ($database->query('SELECT DATABASE()')->fetchColumn() === null) {
        $databaseName = trim((string) (getenv('TILEIMAGEGEN_DB_NAME') ?: ''));
        if ($databaseName === '') {
            $schemas = $database->query(
                "SELECT TABLE_SCHEMA FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'feedback' ORDER BY TABLE_SCHEMA"
            )->fetchAll(PDO::FETCH_COLUMN);
            if (count($schemas) !== 1) {
                throw new RuntimeException('Set TILEIMAGEGEN_DB_NAME to the MariaDB schema containing Tile Image Generator data.');
            }
            $databaseName = (string) $schemas[0];
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $databaseName) !== 1) {
            throw new RuntimeException('The configured Tile Image Generator database name is invalid.');
        }
        $database->exec('USE `' . $databaseName . '`');
    }

    return $database;
}