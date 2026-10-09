<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/feedback_notifications.php';
require_once __DIR__ . '/includes/feedback_inbound.php';
require_once __DIR__ . '/includes/update_newsletter.php';
require_once __DIR__ . '/includes/evolution_automations.php';

$lock = fopen(sys_get_temp_dir() . '/dashboard-minute-runner.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "The dashboard minute runner is already running.\n");
    exit(1);
}

try {
    $summary = [
        'inbound' => feedback_inbound_run(),
        'outbound' => feedback_notifications_run(),
        'newsletters' => update_newsletter_run(),
        'automations' => (static function (): array {
            try {
                return evolution_automations_run();
            } catch (Throwable $error) {
                return ['ran' => 0, 'failed' => 0, 'error' => $error->getMessage()];
            }
        })(),
    ];
    fwrite(STDOUT, json_encode($summary, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Dashboard minute runner failed: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}