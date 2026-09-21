<?php

declare(strict_types=1);

require_once __DIR__ . '/feedback_discord.php';
require_once __DIR__ . '/feedback_mailer.php';

const FEEDBACK_NOTIFICATION_MAX_ATTEMPTS = 3;

function feedback_notifications_tables(PDO $database): array
{
    return [
        'feedback' => project_database_table('tileimagegen', 'feedback'),
        'notifications' => project_database_table('tileimagegen', 'feedback_notifications'),
    ];
}

function feedback_notifications_install(PDO $database): bool
{
    $tables = feedback_notifications_tables($database);
    $tableName = trim($tables['notifications'], '`');
    $check = $database->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
    $check->execute([':table_name' => $tableName]);
    $isNew = (int) $check->fetchColumn() === 0;

    $database->exec('CREATE TABLE IF NOT EXISTS ' . $tables['notifications'] . ' (
        feedback_id CHAR(64) PRIMARY KEY,
        confirmation_emailed_at DATETIME NULL,
        confirmation_email_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        confirmation_email_error VARCHAR(500) NULL,
        discord_notified_at DATETIME NULL,
        discord_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        discord_error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    if ($isNew) {
        $database->exec('INSERT IGNORE INTO ' . $tables['notifications'] . ' (feedback_id, confirmation_emailed_at, discord_notified_at, created_at, updated_at)
            SELECT id, NOW(), NOW(), NOW(), NOW() FROM ' . $tables['feedback']);
    }

    return $isNew;
}

function feedback_notifications_candidates(PDO $database, int $limit): array
{
    $tables = feedback_notifications_tables($database);
    $limit = max(1, min(100, $limit));
    $statement = $database->query('SELECT feedback.*,
            notification.confirmation_emailed_at,
            COALESCE(notification.confirmation_email_attempts, 0) AS confirmation_email_attempts,
            notification.discord_notified_at,
            COALESCE(notification.discord_attempts, 0) AS discord_attempts
        FROM ' . $tables['feedback'] . ' feedback
        LEFT JOIN ' . $tables['notifications'] . ' notification ON notification.feedback_id = feedback.id
        WHERE (notification.feedback_id IS NULL
            OR (notification.confirmation_emailed_at IS NULL AND notification.confirmation_email_attempts < ' . FEEDBACK_NOTIFICATION_MAX_ATTEMPTS . ')
            OR (notification.discord_notified_at IS NULL AND notification.discord_attempts < ' . FEEDBACK_NOTIFICATION_MAX_ATTEMPTS . '))
        ORDER BY feedback.created_at ASC, feedback.public_id ASC
        LIMIT ' . $limit);
    return $statement->fetchAll();
}

function feedback_notifications_ensure_state(PDO $database, string $feedbackId): void
{
    $table = feedback_notifications_tables($database)['notifications'];
    $statement = $database->prepare('INSERT IGNORE INTO ' . $table . ' (feedback_id, created_at, updated_at) VALUES (:feedback_id, NOW(), NOW())');
    $statement->execute([':feedback_id' => $feedbackId]);
}

function feedback_notifications_record(PDO $database, string $feedbackId, string $channel, ?string $error): void
{
    $table = feedback_notifications_tables($database)['notifications'];
    $successColumn = $channel === 'email' ? 'confirmation_emailed_at' : 'discord_notified_at';
    $attemptColumn = $channel === 'email' ? 'confirmation_email_attempts' : 'discord_attempts';
    $errorColumn = $channel === 'email' ? 'confirmation_email_error' : 'discord_error';
    $statement = $database->prepare('UPDATE ' . $table . ' SET
        ' . $successColumn . ' = CASE WHEN :succeeded = 1 THEN NOW() ELSE ' . $successColumn . ' END,
        ' . $attemptColumn . ' = ' . $attemptColumn . ' + 1,
        ' . $errorColumn . ' = :error,
        updated_at = NOW()
        WHERE feedback_id = :feedback_id');
    $statement->execute([
        ':succeeded' => $error === null ? 1 : 0,
        ':error' => $error === null ? null : mb_substr($error, 0, 500),
        ':feedback_id' => $feedbackId,
    ]);
}

function feedback_notifications_run(int $limit = 50): array
{
    $database = project_database_connection('tileimagegen');
    $installed = feedback_notifications_install($database);
    $summary = ['installed' => $installed, 'candidates' => 0, 'email_sent' => 0, 'email_failed' => 0, 'email_unconfigured' => 0, 'discord_sent' => 0, 'discord_failed' => 0, 'discord_unconfigured' => 0];
    if ($installed) return $summary;

    $emailConfigured = feedback_mailer_configured();
    $discordConfigured = feedback_discord_configured();
    foreach (feedback_notifications_candidates($database, $limit) as $feedback) {
        $summary['candidates']++;
        $feedbackId = (string) $feedback['id'];
        feedback_notifications_ensure_state($database, $feedbackId);

        if ($feedback['confirmation_emailed_at'] === null && (int) $feedback['confirmation_email_attempts'] < FEEDBACK_NOTIFICATION_MAX_ATTEMPTS) {
            if (!$emailConfigured) {
                $summary['email_unconfigured']++;
            } else {
                try {
                    feedback_mailer_send_creation($feedback);
                    feedback_notifications_record($database, $feedbackId, 'email', null);
                    $summary['email_sent']++;
                } catch (Throwable $error) {
                    feedback_notifications_record($database, $feedbackId, 'email', $error->getMessage());
                    $summary['email_failed']++;
                }
            }
        }

        if ($feedback['discord_notified_at'] === null && (int) $feedback['discord_attempts'] < FEEDBACK_NOTIFICATION_MAX_ATTEMPTS) {
            if (!$discordConfigured) {
                $summary['discord_unconfigured']++;
            } else {
                try {
                    feedback_discord_send($feedback);
                    feedback_notifications_record($database, $feedbackId, 'discord', null);
                    $summary['discord_sent']++;
                } catch (Throwable $error) {
                    feedback_notifications_record($database, $feedbackId, 'discord', $error->getMessage());
                    $summary['discord_failed']++;
                }
            }
        }
    }

    return $summary;
}