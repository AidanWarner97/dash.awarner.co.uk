<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/feedback_notifications.php';
require_once __DIR__ . '/includes/feedback_inbound.php';

$lock = fopen(sys_get_temp_dir() . '/tileimagegen-feedback-notifications.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "The feedback notification worker is already running.\n");
    exit(1);
}

try {
    $summary = [
        'inbound' => feedback_inbound_run(),
        'outbound' => feedback_notifications_run(),
    ];
    fwrite(STDOUT, json_encode($summary, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Feedback notification worker failed: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}