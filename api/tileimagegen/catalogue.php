<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/catalogue_manager.php';

auth_require();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    if (($_GET['view'] ?? 'catalogue') === 'status') {
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => true,
            'storage' => catalogue_database_storage_status(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    $snapshot = catalogue_database_snapshot();
    if ($snapshot === null) {
        throw new RuntimeException('The catalogue database has not been initialized.');
    }
    $etag = '"catalogue-' . $snapshot['revision'] . '"';
    header('Cache-Control: private, max-age=30, stale-while-revalidate=300');
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    $settings = project_settings('tileimagegen');
    $probePath = 'catalogue/images/placeholder.png';
    $probeUrl = bunny_storage_enabled()
        ? bunny_storage_url($probePath)
        : 'https://' . $settings['domain'] . '/' . $probePath;
    echo json_encode([
        'ok' => true,
        'revision' => $snapshot['revision'],
        'updated_at' => $snapshot['updated_at'],
        'summary' => catalogue_summary($snapshot['catalogue']),
        'image_base_url' => substr($probeUrl, 0, -strlen($probePath)),
        'catalogue' => $snapshot['catalogue'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(503);
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
