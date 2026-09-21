<?php

declare(strict_types=1);

require_once __DIR__ . '/feedback_discord.php';
require_once __DIR__ . '/feedback_mailer.php';

const FEEDBACK_INBOUND_MAX_MESSAGES = 50;
const FEEDBACK_INBOUND_MAX_DISCORD_ATTEMPTS = 3;

function feedback_inbound_configured(): bool
{
    return extension_loaded('imap')
        && trim((string) (getenv('TILEIMAGEGEN_IMAP_HOST') ?: '')) !== ''
        && feedback_inbound_username() !== ''
        && feedback_inbound_password() !== ''
        && feedback_mailer_reply_address(['public_id' => 1]) !== null;
}

function feedback_inbound_username(): string
{
    return trim((string) (getenv('TILEIMAGEGEN_IMAP_USERNAME') ?: getenv('TILEIMAGEGEN_SMTP_USERNAME') ?: ''));
}

function feedback_inbound_password(): string
{
    return (string) (getenv('TILEIMAGEGEN_IMAP_PASSWORD') ?: getenv('TILEIMAGEGEN_SMTP_PASSWORD') ?: '');
}

function feedback_inbound_table(): string
{
    return project_database_table('tileimagegen', 'feedback_inbound_messages');
}

function feedback_inbound_install(PDO $database): void
{
    $database->exec('CREATE TABLE IF NOT EXISTS ' . feedback_inbound_table() . ' (
        message_key CHAR(64) PRIMARY KEY,
        feedback_id CHAR(64) NULL,
        message_id VARCHAR(255) NULL,
        sender VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        body MEDIUMTEXT NULL,
        status VARCHAR(20) NOT NULL,
        error VARCHAR(500) NULL,
        received_at DATETIME NULL,
        processed_at DATETIME NOT NULL,
        discord_notified_at DATETIME NULL,
        discord_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        discord_error VARCHAR(500) NULL,
        INDEX tig_feedback_inbound_feedback_id (feedback_id),
        INDEX tig_feedback_inbound_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function feedback_inbound_mailbox_path(): string
{
    $host = trim((string) getenv('TILEIMAGEGEN_IMAP_HOST'));
    $encryption = strtolower(trim((string) (getenv('TILEIMAGEGEN_IMAP_ENCRYPTION') ?: 'ssl')));
    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) throw new RuntimeException('TILEIMAGEGEN_IMAP_ENCRYPTION must be ssl, tls, or none.');

    $port = (int) (getenv('TILEIMAGEGEN_IMAP_PORT') ?: ($encryption === 'ssl' ? 993 : 143));
    $mailbox = trim((string) (getenv('TILEIMAGEGEN_IMAP_MAILBOX') ?: 'INBOX'));
    if ($host === '' || preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1 || $port < 1 || $port > 65535 || preg_match('/^[A-Za-z0-9._ -]+$/', $mailbox) !== 1) {
        throw new RuntimeException('The IMAP mailbox configuration is invalid.');
    }
    $flags = $encryption === 'none' ? '/imap/notls' : '/imap/' . $encryption;
    return '{' . $host . ':' . $port . $flags . '}' . $mailbox;
}

function feedback_inbound_decode_header(string $value): string
{
    $decoded = '';
    foreach (imap_mime_header_decode($value) as $part) {
        $charset = strtoupper((string) ($part->charset ?? 'UTF-8'));
        $text = (string) ($part->text ?? '');
        $decoded .= in_array($charset, ['DEFAULT', 'UTF-8', 'US-ASCII'], true) ? $text : (mb_convert_encoding($text, 'UTF-8', $charset) ?: $text);
    }
    return trim($decoded);
}

function feedback_inbound_sender(string $from): string
{
    $addresses = imap_rfc822_parse_adrlist($from, '');
    $address = $addresses[0] ?? null;
    if (!is_object($address) || empty($address->mailbox) || empty($address->host)) return '';
    return strtolower((string) $address->mailbox . '@' . (string) $address->host);
}

function feedback_inbound_sender_name(string $from, string $email): string
{
    $addresses = imap_rfc822_parse_adrlist($from, '');
    $name = trim((string) (($addresses[0]->personal ?? '') ?: ''));
    if ($name !== '') return feedback_inbound_decode_header($name);
    return trim((string) strstr($email, '@', true)) ?: 'Email sender';
}

function feedback_inbound_decode_body(string $body, int $encoding, string $charset): string
{
    if ($encoding === ENCBASE64) $body = base64_decode($body, true) ?: '';
    elseif ($encoding === ENCQUOTEDPRINTABLE) $body = quoted_printable_decode($body);
    if ($charset !== '' && !in_array(strtoupper($charset), ['UTF-8', 'US-ASCII'], true)) $body = mb_convert_encoding($body, 'UTF-8', $charset);
    return $body;
}

function feedback_inbound_parts(IMAP\Connection $mailbox, int $uid, object $structure, string $partNumber = ''): array
{
    $result = ['plain' => '', 'html' => ''];
    if ((int) ($structure->type ?? -1) === TYPEMULTIPART && !empty($structure->parts)) {
        foreach ($structure->parts as $index => $part) {
            $childNumber = $partNumber === '' ? (string) ($index + 1) : $partNumber . '.' . ($index + 1);
            $child = feedback_inbound_parts($mailbox, $uid, $part, $childNumber);
            if ($result['plain'] === '' && $child['plain'] !== '') $result['plain'] = $child['plain'];
            if ($result['html'] === '' && $child['html'] !== '') $result['html'] = $child['html'];
        }
        return $result;
    }

    $disposition = strtoupper((string) ($structure->disposition ?? ''));
    $subtype = strtolower((string) ($structure->subtype ?? ''));
    if ((int) ($structure->type ?? -1) !== TYPETEXT || $disposition === 'ATTACHMENT' || !in_array($subtype, ['plain', 'html'], true)) return $result;

    $body = $partNumber === '' ? imap_body($mailbox, $uid, FT_UID | FT_PEEK) : imap_fetchbody($mailbox, $uid, $partNumber, FT_UID | FT_PEEK);
    $charset = '';
    foreach (array_merge((array) ($structure->parameters ?? []), (array) ($structure->dparameters ?? [])) as $parameter) {
        if (strtolower((string) ($parameter->attribute ?? '')) === 'charset') $charset = (string) ($parameter->value ?? '');
    }
    $result[$subtype] = feedback_inbound_decode_body((string) $body, (int) ($structure->encoding ?? ENC7BIT), $charset);
    return $result;
}

function feedback_inbound_clean_body(array $parts): string
{
    $body = (string) ($parts['plain'] ?: $parts['html']);
    if ($parts['plain'] === '' && $body !== '') {
        $body = preg_replace('/<\s*br\s*\/?>/i', "\n", $body) ?? $body;
        $body = preg_replace('/<\/(?:p|div|li)>/i', "\n", $body) ?? $body;
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/\n(?:On .+ wrote:|From:\s.+|-----Original Message-----)\n[\s\S]*$/i', '', $body) ?? $body;
    $body = preg_replace('/\n>.*(?:\n>.*)*$/s', '', $body) ?? $body;
    $body = preg_replace('/\n--\s*\n[\s\S]*$/', '', $body) ?? $body;
    $body = preg_replace("/\n{3,}/", "\n\n", trim($body)) ?? trim($body);
    return mb_substr($body, 0, 20000);
}

function feedback_inbound_public_id(string $subject): ?int
{
    if (preg_match('/\bfeedback\s*#(\d+)\b/i', $subject, $matches) !== 1) return null;
    $publicId = (int) $matches[1];
    return $publicId > 0 ? $publicId : null;
}

function feedback_inbound_table_exists(PDO $database, string $tableName): bool
{
    $statement = $database->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
    $statement->execute([':table_name' => $tableName]);
    return (int) $statement->fetchColumn() > 0;
}

function feedback_inbound_store(PDO $database, array $message, ?array $feedback, string $status, ?string $error): bool
{
    $statement = $database->prepare('INSERT IGNORE INTO ' . feedback_inbound_table() . ' (message_key, feedback_id, message_id, sender, subject, body, status, error, received_at, processed_at)
        VALUES (:message_key, :feedback_id, :message_id, :sender, :subject, :body, :status, :error, :received_at, NOW())');
    $statement->execute([
        ':message_key' => $message['message_key'], ':feedback_id' => $feedback['id'] ?? null,
        ':message_id' => $message['message_id'] ?: null, ':sender' => $message['sender'],
        ':subject' => mb_substr($message['subject'], 0, 255), ':body' => $status === 'accepted' ? $message['body'] : null,
        ':status' => $status, ':error' => $error === null ? null : mb_substr($error, 0, 500), ':received_at' => $message['received_at'],
    ]);
    return $statement->rowCount() === 1;
}

function feedback_inbound_create_feedback(PDO $database, array $message): array
{
    $feedbackTable = project_database_table('tileimagegen', 'feedback');
    $name = trim((string) ($message['sender_name'] ?? '')) ?: 'Email sender';
    $nameParts = preg_split('/\s+/', $name, 2) ?: [];
    $firstName = mb_substr((string) ($nameParts[0] ?? 'Email'), 0, 80);
    $lastName = mb_substr((string) ($nameParts[1] ?? ''), 0, 80);
    $subject = trim((string) ($message['subject'] ?? '')) ?: 'Email feedback';

    $nextPublicId = (int) $database->query('SELECT COALESCE(MAX(public_id), 0) + 1 FROM ' . $feedbackTable)->fetchColumn();
    $feedback = [
        'id' => bin2hex(random_bytes(32)),
        'public_id' => $nextPublicId,
        'name' => mb_substr($name, 0, 255),
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => (string) $message['sender'],
        'subject' => mb_substr($subject, 0, 180),
        'feedback_type' => 'general',
        'message' => mb_substr((string) $message['body'], 0, 10000),
        'contact_allowed' => 1,
        'status' => 'new',
        'created_at' => (string) $message['received_at'],
    ];
    $statement = $database->prepare('INSERT INTO ' . $feedbackTable . ' (id, public_id, name, first_name, last_name, email, subject, feedback_type, message, contact_allowed, status, created_at)
        VALUES (:id, :public_id, :name, :first_name, :last_name, :email, :subject, :feedback_type, :message, :contact_allowed, :status, :created_at)');
    $statement->execute($feedback);
    return $feedback;
}

function feedback_inbound_mark_feedback_unread(PDO $database, string $feedbackId): void
{
    if (feedback_inbound_table_exists($database, 'feedback_admin_state')) {
        $statement = $database->prepare('INSERT INTO feedback_admin_state (feedback_id, read_at, updated_at) VALUES (:feedback_id, NULL, NOW()) ON DUPLICATE KEY UPDATE read_at = NULL, updated_at = NOW()');
        $statement->execute([':feedback_id' => $feedbackId]);
    }
    if (feedback_inbound_table_exists($database, 'feedback_audit_log')) {
        $statement = $database->prepare('INSERT INTO feedback_audit_log (feedback_id, action, old_value, new_value, actor, created_at) VALUES (:feedback_id, "email_reply_received", NULL, NULL, "Email reply worker", NOW())');
        $statement->execute([':feedback_id' => $feedbackId]);
    }
}

function feedback_inbound_notify_pending(PDO $database, array &$summary): void
{
    $statement = $database->query('SELECT inbound.message_key, inbound.body, inbound.received_at, feedback.* FROM ' . feedback_inbound_table() . ' inbound
        INNER JOIN ' . project_database_table('tileimagegen', 'feedback') . ' feedback ON feedback.id = inbound.feedback_id
        WHERE inbound.status = "accepted" AND inbound.discord_notified_at IS NULL AND inbound.discord_attempts < ' . FEEDBACK_INBOUND_MAX_DISCORD_ATTEMPTS . '
        ORDER BY inbound.processed_at ASC LIMIT 50');
    foreach ($statement->fetchAll() as $reply) {
        if (!feedback_discord_configured()) {
            $summary['discord_unconfigured']++;
            continue;
        }
        try {
            feedback_discord_send_reply($reply, $reply);
            $update = $database->prepare('UPDATE ' . feedback_inbound_table() . ' SET discord_notified_at = NOW(), discord_attempts = discord_attempts + 1, discord_error = NULL WHERE message_key = :message_key');
            $update->execute([':message_key' => $reply['message_key']]);
            $summary['discord_sent']++;
        } catch (Throwable $error) {
            $update = $database->prepare('UPDATE ' . feedback_inbound_table() . ' SET discord_attempts = discord_attempts + 1, discord_error = :error WHERE message_key = :message_key');
            $update->execute([':error' => mb_substr($error->getMessage(), 0, 500), ':message_key' => $reply['message_key']]);
            $summary['discord_failed']++;
        }
    }
}

function feedback_inbound_run(): array
{
    $summary = ['configured' => feedback_inbound_configured(), 'checked' => 0, 'created' => 0, 'accepted' => 0, 'rejected' => 0, 'duplicate' => 0, 'failed' => 0, 'discord_sent' => 0, 'discord_failed' => 0, 'discord_unconfigured' => 0];
    $database = project_database_connection('tileimagegen');
    feedback_inbound_install($database);
    if (!$summary['configured']) {
        feedback_inbound_notify_pending($database, $summary);
        return $summary;
    }

    $mailbox = @imap_open(feedback_inbound_mailbox_path(), feedback_inbound_username(), feedback_inbound_password(), 0, 1);
    if ($mailbox === false) throw new RuntimeException('The feedback IMAP mailbox could not be opened.');
    try {
        $uids = imap_search($mailbox, 'UNSEEN', SE_UID) ?: [];
        sort($uids, SORT_NUMERIC);
        foreach (array_slice($uids, 0, FEEDBACK_INBOUND_MAX_MESSAGES) as $uid) {
            $summary['checked']++;
            try {
                $rawHeaders = (string) imap_fetchheader($mailbox, $uid, FT_UID);
                $overview = imap_fetch_overview($mailbox, (string) $uid, FT_UID)[0] ?? null;
                $structure = imap_fetchstructure($mailbox, $uid, FT_UID);
                if (!is_object($overview) || !is_object($structure)) throw new RuntimeException('The message could not be read.');
                $messageId = trim((string) ($overview->message_id ?? ''));
                $sender = feedback_inbound_sender((string) ($overview->from ?? ''));
                $message = [
                    'message_key' => hash('sha256', strtolower($messageId !== '' ? $messageId : $rawHeaders)),
                    'message_id' => $messageId,
                    'sender' => $sender,
                    'sender_name' => feedback_inbound_sender_name((string) ($overview->from ?? ''), $sender),
                    'subject' => feedback_inbound_decode_header((string) ($overview->subject ?? 'Email reply')),
                    'body' => feedback_inbound_clean_body(feedback_inbound_parts($mailbox, $uid, $structure)),
                    'received_at' => date('Y-m-d H:i:s', (int) ($overview->udate ?? time())),
                ];
                $exists = $database->prepare('SELECT 1 FROM ' . feedback_inbound_table() . ' WHERE message_key = :message_key');
                $exists->execute([':message_key' => $message['message_key']]);
                if ($exists->fetchColumn()) {
                    $summary['duplicate']++;
                    imap_setflag_full($mailbox, (string) $uid, '\\Seen', ST_UID);
                    continue;
                }

                $publicId = feedback_inbound_public_id($message['subject']);
                $feedback = null;
                $reason = null;
                $isNewFeedback = $publicId === null;
                if (!filter_var($message['sender'], FILTER_VALIDATE_EMAIL)) $reason = 'The sender email address is invalid.';
                elseif ($message['body'] === '') $reason = 'The email has no readable message body.';
                elseif (!$isNewFeedback) {
                    $lookup = $database->prepare('SELECT * FROM ' . project_database_table('tileimagegen', 'feedback') . ' WHERE public_id = :public_id LIMIT 1');
                    $lookup->execute([':public_id' => $publicId]);
                    $feedback = $lookup->fetch() ?: null;
                    if ($feedback === null) $reason = 'The referenced feedback entry was not found.';
                    elseif (!hash_equals(strtolower(trim((string) $feedback['email'])), $message['sender'])) $reason = 'The sender does not match the feedback submitter.';
                }

                $accepted = $reason === null;
                $status = $accepted ? ($isNewFeedback ? 'created' : 'accepted') : 'rejected';
                $database->beginTransaction();
                try {
                    if ($accepted && $isNewFeedback) $feedback = feedback_inbound_create_feedback($database, $message);
                    $inserted = feedback_inbound_store($database, $message, $accepted ? $feedback : null, $status, $reason);
                    if ($inserted && $accepted && !$isNewFeedback) feedback_inbound_mark_feedback_unread($database, (string) $feedback['id']);
                    $database->commit();
                } catch (Throwable $error) {
                    $database->rollBack();
                    throw $error;
                }
                $summary[$status]++;
                imap_setflag_full($mailbox, (string) $uid, '\\Seen', ST_UID);
            } catch (Throwable) {
                $summary['failed']++;
            }
        }
    } finally {
        imap_close($mailbox);
    }
    feedback_inbound_notify_pending($database, $summary);
    return $summary;
}

function feedback_inbound_list(string $feedbackId): array
{
    $database = project_database_connection('tileimagegen');
    feedback_inbound_install($database);
    $statement = $database->prepare('SELECT sender, subject, body, received_at FROM ' . feedback_inbound_table() . ' WHERE feedback_id = :feedback_id AND status = "accepted" ORDER BY received_at ASC, processed_at ASC');
    $statement->execute([':feedback_id' => $feedbackId]);
    return $statement->fetchAll();
}