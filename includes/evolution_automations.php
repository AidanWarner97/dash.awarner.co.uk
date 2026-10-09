<?php

declare(strict_types=1);

use Cron\CronExpression;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/project_settings.php';

function evolution_automation_definitions(): array
{
    return [
        'maintenance' => ['name' => 'Cache and statistics maintenance', 'script' => 'cron.php', 'schedule' => '*/15 * * * *', 'description' => 'Refreshes bucket caches, download statistics, and expired cache entries.'],
        'push_queue' => ['name' => 'Release push queue', 'script' => 'cron_push_queue.php', 'schedule' => '* * * * *', 'description' => 'Processes queued release pushes and completion callbacks.'],
        'bunny_sync' => ['name' => 'Bunny storage sync', 'script' => 'cron_bunny_sync.php', 'schedule' => '* * * * *', 'description' => 'Synchronises source release files with Bunny Storage.'],
        'bunny_watch' => ['name' => 'Bunny release watcher', 'script' => 'cron_bunny_watch.php', 'schedule' => '*/5 * * * *', 'description' => 'Scans for stable new release files and uploads them.'],
        'hashes' => ['name' => 'OTA hash refresh', 'script' => 'cron_hashes.php', 'schedule' => '*/30 * * * *', 'description' => 'Refreshes file hashes and OTA metadata.'],
        'manifest' => ['name' => 'Listing manifest', 'script' => 'cron_manifest_index.php', 'schedule' => '*/15 * * * *', 'description' => 'Builds the indexed bucket listing manifest.'],
        'daily_log_csv' => ['name' => 'Daily access-log CSV', 'script' => 'cron_daily_log_to_csv.php', 'schedule' => '10 0 * * *', 'description' => 'Converts the previous day application access log to CSV.'],
        'archive_logs' => ['name' => 'Monthly log archive', 'script' => 'cron_archive_monthly_logs.php', 'schedule' => '30 0 1 * *', 'description' => 'Compresses and retains completed monthly logs.'],
    ];
}

function evolution_automation_frequencies(): array
{
    return [
        '* * * * *' => 'Every minute',
        '*/5 * * * *' => 'Every 5 minutes',
        '*/15 * * * *' => 'Every 15 minutes',
        '*/30 * * * *' => 'Every 30 minutes',
        '0 * * * *' => 'Hourly',
        '0 */6 * * *' => 'Every 6 hours',
        '0 0 * * *' => 'Daily',
        '10 0 * * *' => 'Daily at 00:10',
        '30 0 1 * *' => 'Monthly on day 1',
    ];
}

function evolution_automations_database(): PDO
{
    return project_database_connection('evolutioncdn');
}

function evolution_automations_install(PDO $database): void
{
    $settingsTable = project_database_table('evolutioncdn', 'dashboard_automations');
    $runsTable = project_database_table('evolutioncdn', 'dashboard_automation_runs');
    $database->exec("CREATE TABLE IF NOT EXISTS {$settingsTable} (
        automation_key VARCHAR(64) NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        schedule VARCHAR(100) NOT NULL,
        run_requested_at DATETIME NULL,
        last_started_at DATETIME NULL,
        last_finished_at DATETIME NULL,
        last_status VARCHAR(20) NULL,
        last_error VARCHAR(1000) NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $database->exec("CREATE TABLE IF NOT EXISTS {$runsTable} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        automation_key VARCHAR(64) NOT NULL,
        trigger_type VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        duration_ms BIGINT UNSIGNED NULL,
        exit_code INT NULL,
        output MEDIUMTEXT NULL,
        error_message VARCHAR(1000) NULL,
        INDEX automation_runs_key_started (automation_key, started_at),
        INDEX automation_runs_status_started (status, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $insert = $database->prepare("INSERT IGNORE INTO {$settingsTable} (automation_key, enabled, schedule, updated_at) VALUES (:automation_key, 0, :schedule, :updated_at)");
    foreach (evolution_automation_definitions() as $key => $definition) {
        $insert->execute([':automation_key' => $key, ':schedule' => $definition['schedule'], ':updated_at' => gmdate('Y-m-d H:i:s')]);
    }
}

function evolution_automations_list(PDO $database): array
{
    evolution_automations_install($database);
    $settingsTable = project_database_table('evolutioncdn', 'dashboard_automations');
    $rows = $database->query("SELECT * FROM {$settingsTable}")->fetchAll();
    $stored = [];
    foreach ($rows as $row) $stored[(string) $row['automation_key']] = $row;

    $automations = [];
    foreach (evolution_automation_definitions() as $key => $definition) {
        $row = $stored[$key] ?? [];
        $schedule = (string) ($row['schedule'] ?? $definition['schedule']);
        $nextRun = null;
        if (!empty($row['enabled']) && CronExpression::isValidExpression($schedule)) {
            $nextRun = (new CronExpression($schedule))->getNextRunDate()->format('Y-m-d H:i:s');
        }
        $automations[] = $definition + $row + ['key' => $key, 'schedule' => $schedule, 'next_run_at' => $nextRun];
    }
    return $automations;
}

function evolution_automations_recent_runs(PDO $database, int $limit = 30): array
{
    evolution_automations_install($database);
    $runsTable = project_database_table('evolutioncdn', 'dashboard_automation_runs');
    $limit = max(1, min(100, $limit));
    return $database->query("SELECT * FROM {$runsTable} ORDER BY started_at DESC, id DESC LIMIT {$limit}")->fetchAll();
}

function evolution_automation_save(PDO $database, string $key, bool $enabled, string $schedule): void
{
    if (!updates_writes_enabled()) throw new RuntimeException('Automation changes are disabled.');
    if (!isset(evolution_automation_definitions()[$key])) throw new InvalidArgumentException('Select a valid automation.');
    $schedule = trim($schedule);
    if (!isset(evolution_automation_frequencies()[$schedule])) throw new InvalidArgumentException('Select a valid automation frequency.');
    evolution_automations_install($database);
    $settingsTable = project_database_table('evolutioncdn', 'dashboard_automations');
    $statement = $database->prepare("UPDATE {$settingsTable} SET enabled = :enabled, schedule = :schedule, updated_at = :updated_at WHERE automation_key = :automation_key");
    $statement->execute([':enabled' => $enabled ? 1 : 0, ':schedule' => $schedule, ':updated_at' => gmdate('Y-m-d H:i:s'), ':automation_key' => $key]);
}

function evolution_automation_set_enabled(PDO $database, string $key, bool $enabled): void
{
    if (!updates_writes_enabled()) throw new RuntimeException('Automation changes are disabled.');
    if (!isset(evolution_automation_definitions()[$key])) throw new InvalidArgumentException('Select a valid automation.');
    evolution_automations_install($database);
    $settingsTable = project_database_table('evolutioncdn', 'dashboard_automations');
    $statement = $database->prepare("UPDATE {$settingsTable} SET enabled = :enabled, updated_at = :updated_at WHERE automation_key = :automation_key");
    $statement->execute([':enabled' => $enabled ? 1 : 0, ':updated_at' => gmdate('Y-m-d H:i:s'), ':automation_key' => $key]);
}

function evolution_automation_request_run(PDO $database, string $key): void
{
    if (!updates_writes_enabled()) throw new RuntimeException('Automation changes are disabled.');
    if (!isset(evolution_automation_definitions()[$key])) throw new InvalidArgumentException('Select a valid automation.');
    evolution_automations_install($database);
    $settingsTable = project_database_table('evolutioncdn', 'dashboard_automations');
    $statement = $database->prepare("UPDATE {$settingsTable} SET run_requested_at = :requested_at, updated_at = :updated_at WHERE automation_key = :automation_key");
    $now = gmdate('Y-m-d H:i:s');
    $statement->execute([':requested_at' => $now, ':updated_at' => $now, ':automation_key' => $key]);
}

function evolution_automation_execute(string $script, int $timeoutSeconds = 3300): array
{
    $root = realpath(project_settings('evolutioncdn')['root']);
    if ($root === false) throw new RuntimeException('The Evolution X CDN source directory is unavailable.');
    $scriptPath = $root . '/' . $script;
    if (!is_file($scriptPath) || !is_readable($scriptPath)) throw new RuntimeException('The automation script is unavailable.');

    $pipes = [];
    $process = proc_open([PHP_BINARY, $scriptPath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('The automation process could not be started.');
    foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
    $started = microtime(true);
    $output = '';
    $timedOut = false;
    $observedExitCode = null;
    while (true) {
        foreach ($pipes as $pipe) {
            $chunk = stream_get_contents($pipe);
            if ($chunk !== false && $chunk !== '') $output .= $chunk;
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            $observedExitCode = $status['exitcode'] >= 0 ? (int) $status['exitcode'] : null;
            break;
        }
        if (microtime(true) - $started > $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process, 15);
            break;
        }
        usleep(100000);
    }
    foreach ($pipes as $pipe) {
        $chunk = stream_get_contents($pipe);
        if ($chunk !== false) $output .= $chunk;
        fclose($pipe);
    }
    $exitCode = proc_close($process);
    if ($exitCode < 0 && $observedExitCode !== null) $exitCode = $observedExitCode;
    if ($timedOut) $exitCode = 124;
    if (strlen($output) > 262144) $output = substr($output, -262144);
    return ['exit_code' => $exitCode, 'output' => $output, 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'timed_out' => $timedOut];
}

function evolution_automations_run(): array
{
    $database = evolution_automations_database();
    evolution_automations_install($database);
    if ((int) $database->query("SELECT GET_LOCK('dashboard_evolution_automations', 0)")->fetchColumn() !== 1) {
        return ['ran' => 0, 'failed' => 0, 'locked' => true];
    }

    $summary = ['ran' => 0, 'failed' => 0, 'locked' => false];
    $settingsTable = project_database_table('evolutioncdn', 'dashboard_automations');
    $runsTable = project_database_table('evolutioncdn', 'dashboard_automation_runs');
    try {
        $rows = $database->query("SELECT * FROM {$settingsTable}")->fetchAll();
        $definitions = evolution_automation_definitions();
        $now = new DateTimeImmutable('now');
        $minuteStart = gmdate('Y-m-d H:i:00');
        foreach ($rows as $row) {
            $key = (string) $row['automation_key'];
            if (!isset($definitions[$key])) continue;
            $manual = $row['run_requested_at'] !== null;
            $scheduled = !empty($row['enabled']) && CronExpression::isValidExpression((string) $row['schedule'])
                && (new CronExpression((string) $row['schedule']))->isDue($now)
                && ($row['last_started_at'] === null || (string) $row['last_started_at'] < $minuteStart);
            if (!$manual && !$scheduled) continue;

            $startedAt = gmdate('Y-m-d H:i:s');
            $claim = $database->prepare("UPDATE {$settingsTable} SET run_requested_at = NULL, last_started_at = :started_at, last_status = 'running', last_error = NULL WHERE automation_key = :automation_key");
            $claim->execute([':started_at' => $startedAt, ':automation_key' => $key]);
            $run = $database->prepare("INSERT INTO {$runsTable} (automation_key, trigger_type, status, started_at) VALUES (:automation_key, :trigger_type, 'running', :started_at)");
            $run->execute([':automation_key' => $key, ':trigger_type' => $manual ? 'manual' : 'scheduled', ':started_at' => $startedAt]);
            $runId = (int) $database->lastInsertId();

            try {
                $result = evolution_automation_execute($definitions[$key]['script']);
                $success = $result['exit_code'] === 0;
                $error = $success ? null : ($result['timed_out'] ? 'Automation timed out.' : 'Automation exited with code ' . $result['exit_code'] . '.');
            } catch (Throwable $exception) {
                $result = ['exit_code' => null, 'output' => '', 'duration_ms' => 0];
                $success = false;
                $error = mb_substr($exception->getMessage(), 0, 1000);
            }
            $finishedAt = gmdate('Y-m-d H:i:s');
            $status = $success ? 'completed' : 'failed';
            $database->prepare("UPDATE {$runsTable} SET status = :status, finished_at = :finished_at, duration_ms = :duration_ms, exit_code = :exit_code, output = :output, error_message = :error WHERE id = :id")
                ->execute([':status' => $status, ':finished_at' => $finishedAt, ':duration_ms' => $result['duration_ms'], ':exit_code' => $result['exit_code'], ':output' => $result['output'], ':error' => $error, ':id' => $runId]);
            $database->prepare("UPDATE {$settingsTable} SET last_finished_at = :finished_at, last_status = :status, last_error = :error WHERE automation_key = :automation_key")
                ->execute([':finished_at' => $finishedAt, ':status' => $status, ':error' => $error, ':automation_key' => $key]);
            $summary['ran']++;
            if (!$success) $summary['failed']++;
        }
    } finally {
        $database->query("SELECT RELEASE_LOCK('dashboard_evolution_automations')");
    }
    return $summary;
}