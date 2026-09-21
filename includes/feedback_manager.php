<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';
require_once __DIR__ . '/update_manager.php';
require_once __DIR__ . '/feedback_mailer.php';
require_once __DIR__ . '/feedback_inbound.php';

function feedback_management_statuses(): array
{
    return [
        'new' => 'New',
        'open' => 'Open',
        'investigating' => 'Investigating',
        'building' => 'Building',
        'fixed' => 'Fixed',
        'added' => 'Added',
        'wont_fix' => "Closed, won't fix",
        'wont_add' => "Closed, won't add",
    ];
}

function feedback_management_database(): PDO
{
    return project_database_connection('tileimagegen');
}

function feedback_management_ensure_tables(PDO $database): void
{
    if (!updates_writes_enabled()) {
        return;
    }

    $database->exec('CREATE TABLE IF NOT EXISTS feedback_admin_state (
        feedback_id CHAR(64) PRIMARY KEY,
        read_at DATETIME NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $database->exec('CREATE TABLE IF NOT EXISTS feedback_admin_notes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        feedback_id CHAR(64) NOT NULL,
        note TEXT NOT NULL,
        author VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX feedback_admin_notes_feedback_id (feedback_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $database->exec('CREATE TABLE IF NOT EXISTS feedback_audit_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        feedback_id CHAR(64) NOT NULL,
        action VARCHAR(40) NOT NULL,
        old_value VARCHAR(255) NULL,
        new_value VARCHAR(255) NULL,
        actor VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX feedback_audit_feedback_id (feedback_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $database->exec('CREATE TABLE IF NOT EXISTS ' . project_database_table('tileimagegen', 'feedback_responses') . ' (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        feedback_id CHAR(64) NOT NULL,
        body TEXT NOT NULL,
        author VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL,
        emailed_at DATETIME NULL,
        email_error VARCHAR(500) NULL,
        INDEX tig_feedback_responses_feedback_id (feedback_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function feedback_management_tables_available(PDO $database): bool
{
    try {
        $database->query('SELECT 1 FROM feedback_admin_state LIMIT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function feedback_management_list(array $filters): array
{
    $database = feedback_management_database();
    feedback_management_ensure_tables($database);
    $hasManagementTables = feedback_management_tables_available($database);
    $feedbackTable = project_database_table('tileimagegen', 'feedback');
    $stateJoin = $hasManagementTables ? ' LEFT JOIN feedback_admin_state admin_state ON admin_state.feedback_id = feedback.id' : '';
    $readColumn = $hasManagementTables ? 'admin_state.read_at' : 'NULL';
    $sql = "SELECT feedback.id, feedback.public_id, feedback.first_name, feedback.last_name, feedback.email, feedback.subject, feedback.feedback_type, feedback.status, feedback.created_at, {$readColumn} AS read_at FROM {$feedbackTable} feedback{$stateJoin} WHERE 1=1";
    $params = [];

    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $sql .= ' AND (feedback.subject LIKE :subject OR feedback.message LIKE :message OR feedback.email LIKE :email)';
        $params[':subject'] = '%' . $search . '%';
        $params[':message'] = '%' . $search . '%';
        $params[':email'] = '%' . $search . '%';
    }

    $status = (string) ($filters['status'] ?? 'all');
    if (array_key_exists($status, feedback_management_statuses())) {
        $sql .= ' AND feedback.status = :status';
        $params[':status'] = $status;
    }

    $type = (string) ($filters['type'] ?? 'all');
    if (in_array($type, ['general', 'bug'], true)) {
        $sql .= ' AND feedback.feedback_type = :type';
        $params[':type'] = $type;
    }

    $read = (string) ($filters['read'] ?? 'all');
    if ($hasManagementTables && $read === 'unread') $sql .= ' AND admin_state.read_at IS NULL';
    if ($hasManagementTables && $read === 'read') $sql .= ' AND admin_state.read_at IS NOT NULL';
    $sql .= ' ORDER BY feedback.created_at DESC LIMIT 200';

    $statement = $database->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function feedback_management_find(int $publicId): ?array
{
    $database = feedback_management_database();
    feedback_management_ensure_tables($database);
    $hasManagementTables = feedback_management_tables_available($database);
    $feedbackTable = project_database_table('tileimagegen', 'feedback');
    $stateJoin = $hasManagementTables ? ' LEFT JOIN feedback_admin_state admin_state ON admin_state.feedback_id = feedback.id' : '';
    $readColumn = $hasManagementTables ? 'admin_state.read_at' : 'NULL';
    $statement = $database->prepare("SELECT feedback.*, {$readColumn} AS read_at FROM {$feedbackTable} feedback{$stateJoin} WHERE feedback.public_id = :public_id LIMIT 1");
    $statement->execute([':public_id' => $publicId]);
    $entry = $statement->fetch();
    if (!is_array($entry)) return null;

    $entry['notes'] = [];
    $entry['responses'] = [];
    $entry['email_replies'] = [];
    $entry['audit'] = [];
    if ($hasManagementTables) {
        $notes = $database->prepare('SELECT id, note, author, created_at FROM feedback_admin_notes WHERE feedback_id = :feedback_id ORDER BY created_at DESC, id DESC');
        $notes->execute([':feedback_id' => $entry['id']]);
        $entry['notes'] = $notes->fetchAll();
        $responses = $database->prepare('SELECT id, body, author, created_at, emailed_at, email_error FROM ' . project_database_table('tileimagegen', 'feedback_responses') . ' WHERE feedback_id = :feedback_id ORDER BY created_at ASC, id ASC');
        $responses->execute([':feedback_id' => $entry['id']]);
        $entry['responses'] = $responses->fetchAll();
        $entry['email_replies'] = feedback_inbound_list((string) $entry['id']);
        $audit = $database->prepare('SELECT action, old_value, new_value, actor, created_at FROM feedback_audit_log WHERE feedback_id = :feedback_id ORDER BY created_at DESC, id DESC LIMIT 20');
        $audit->execute([':feedback_id' => $entry['id']]);
        $entry['audit'] = $audit->fetchAll();
    }

    return $entry;
}

function feedback_management_update_status(int $publicId, string $status, string $actor = 'Aidan Warner'): void
{
    feedback_management_assert_writes();
    if (!array_key_exists($status, feedback_management_statuses())) throw new InvalidArgumentException('Select a valid status.');
    $database = feedback_management_database();
    feedback_management_ensure_tables($database);
    $entry = feedback_management_find($publicId);
    if ($entry === null) throw new RuntimeException('Feedback entry not found.');
    if ($entry['status'] === $status) return;

    $database->beginTransaction();
    try {
        $statement = $database->prepare('UPDATE ' . project_database_table('tileimagegen', 'feedback') . ' SET status = :status WHERE id = :id');
        $statement->execute([':status' => $status, ':id' => $entry['id']]);
        feedback_management_audit($database, (string) $entry['id'], 'status_changed', (string) $entry['status'], $status, $actor);
        $database->commit();
    } catch (Throwable $error) {
        $database->rollBack();
        throw $error;
    }
}

function feedback_management_set_read(int $publicId, bool $read, string $actor = 'Aidan Warner'): void
{
    feedback_management_assert_writes();
    $database = feedback_management_database();
    feedback_management_ensure_tables($database);
    $entry = feedback_management_find($publicId);
    if ($entry === null) throw new RuntimeException('Feedback entry not found.');
    $now = date('Y-m-d H:i:s');
    $readAt = $read ? $now : null;
    $sql = 'INSERT INTO feedback_admin_state (feedback_id, read_at, updated_at) VALUES (:id, :read_at, :updated_at) ON DUPLICATE KEY UPDATE read_at = VALUES(read_at), updated_at = VALUES(updated_at)';
    $database->beginTransaction();
    try {
        $database->prepare($sql)->execute([':id' => $entry['id'], ':read_at' => $readAt, ':updated_at' => $now]);
        feedback_management_audit($database, (string) $entry['id'], $read ? 'marked_read' : 'marked_unread', null, null, $actor);
        $database->commit();
    } catch (Throwable $error) {
        $database->rollBack();
        throw $error;
    }
}

function feedback_management_add_note(int $publicId, string $note, string $actor = 'Aidan Warner'): void
{
    feedback_management_assert_writes();
    $note = trim($note);
    if ($note === '' || strlen($note) > 5000) throw new InvalidArgumentException('Enter a note no longer than 5,000 characters.');
    $database = feedback_management_database();
    feedback_management_ensure_tables($database);
    $entry = feedback_management_find($publicId);
    if ($entry === null) throw new RuntimeException('Feedback entry not found.');
    $database->beginTransaction();
    try {
        $statement = $database->prepare('INSERT INTO feedback_admin_notes (feedback_id, note, author, created_at) VALUES (:feedback_id, :note, :author, :created_at)');
        $statement->execute([':feedback_id' => $entry['id'], ':note' => $note, ':author' => $actor, ':created_at' => date('Y-m-d H:i:s')]);
        feedback_management_audit($database, (string) $entry['id'], 'note_added', null, null, $actor);
        $database->commit();
    } catch (Throwable $error) {
        $database->rollBack();
        throw $error;
    }
}

function feedback_management_add_public_response(int $publicId, string $body, string $actor = 'Aidan Warner'): array
{
    feedback_management_assert_writes();
    $body = trim($body);
    if ($body === '' || mb_strlen($body) > 10000) throw new InvalidArgumentException('Enter a public comment no longer than 10,000 characters.');

    $database = feedback_management_database();
    feedback_management_ensure_tables($database);
    $entry = feedback_management_find($publicId);
    if ($entry === null) throw new RuntimeException('Feedback entry not found.');

    $now = date('Y-m-d H:i:s');
    $database->beginTransaction();
    try {
        $statement = $database->prepare('INSERT INTO ' . project_database_table('tileimagegen', 'feedback_responses') . ' (feedback_id, body, author, created_at) VALUES (:feedback_id, :body, :author, :created_at)');
        $statement->execute([':feedback_id' => $entry['id'], ':body' => $body, ':author' => $actor, ':created_at' => $now]);
        $responseId = (int) $database->lastInsertId();
        feedback_management_audit($database, (string) $entry['id'], 'public_comment_added', null, null, $actor);
        $database->commit();
    } catch (Throwable $error) {
        $database->rollBack();
        throw $error;
    }

    if (empty($entry['contact_allowed'])) {
        return ['delivery' => 'not_allowed', 'message' => 'Public comment added. The submitter did not consent to email contact.'];
    }

    try {
        feedback_mailer_send_response($entry, $body, $actor);
        $database->prepare('UPDATE ' . project_database_table('tileimagegen', 'feedback_responses') . ' SET emailed_at = :emailed_at, email_error = NULL WHERE id = :id')->execute([':emailed_at' => date('Y-m-d H:i:s'), ':id' => $responseId]);
        feedback_management_audit($database, (string) $entry['id'], 'comment_email_sent', null, null, $actor);
        return ['delivery' => 'sent', 'message' => 'Public comment added and emailed to the submitter.'];
    } catch (Throwable $error) {
        $database->prepare('UPDATE ' . project_database_table('tileimagegen', 'feedback_responses') . ' SET email_error = :email_error WHERE id = :id')->execute([':email_error' => mb_substr($error->getMessage(), 0, 500), ':id' => $responseId]);
        feedback_management_audit($database, (string) $entry['id'], 'comment_email_failed', null, null, $actor);
        return ['delivery' => 'failed', 'message' => $error->getMessage()];
    }
}

function feedback_management_audit(PDO $database, string $feedbackId, string $action, ?string $oldValue, ?string $newValue, string $actor): void
{
    $statement = $database->prepare('INSERT INTO feedback_audit_log (feedback_id, action, old_value, new_value, actor, created_at) VALUES (:feedback_id, :action, :old_value, :new_value, :actor, :created_at)');
    $statement->execute([':feedback_id' => $feedbackId, ':action' => $action, ':old_value' => $oldValue, ':new_value' => $newValue, ':actor' => $actor, ':created_at' => date('Y-m-d H:i:s')]);
}

function feedback_management_assert_writes(): void
{
    if (!updates_writes_enabled()) throw new RuntimeException('Feedback changes are disabled until dashboard authentication is configured.');
}