<?php

declare(strict_types=1);

require_once __DIR__ . '/feedback_mailer.php';
require_once __DIR__ . '/update_manager.php';

function update_newsletter_db(): PDO
{
    return project_database_connection('tileimagegen');
}

function update_newsletter_install(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS tig_update_newsletter_deliveries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        post_filename VARCHAR(255) NOT NULL,
        post_title VARCHAR(180) NOT NULL,
        post_slug VARCHAR(180) NOT NULL,
        recipient_email VARCHAR(254) NOT NULL,
        consent_at DATETIME NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        queued_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        last_error VARCHAR(1000) NULL,
        UNIQUE KEY unique_update_recipient (post_filename, recipient_email),
        KEY pending_deliveries (status, attempts, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function update_newsletter_consent_count(PDO $db): int
{
    $statement = $db->query('SELECT COUNT(*) FROM tig_newsletter_subscribers WHERE subscribed = 1 AND consent_at IS NOT NULL');
    return (int) $statement->fetchColumn();
}

function update_newsletter_public_url(array $post, array $settings): string
{
    return 'https://' . $settings['domain'] . '/updates/' . rawurlencode((string) $post['slug']);
}

function update_newsletter_content_html(array $post, array $settings): string
{
    $domain = preg_quote($settings['domain'], '~');
    $html = (string) ($post['content_html'] ?? '');
    $html = preg_replace('~(href|src)="/(?!/)~', '$1="https://' . $settings['domain'] . '/', $html) ?? $html;
    $styles = [
        '<h1>' => '<h1 style="margin:28px 0 12px;color:#ffffff;font-size:25px;line-height:1.3;">',
        '<h2>' => '<h2 style="margin:26px 0 12px;color:#ffffff;font-size:22px;line-height:1.35;">',
        '<h3>' => '<h3 style="margin:22px 0 10px;color:#ffffff;font-size:18px;line-height:1.4;">',
        '<p>' => '<p style="margin:0 0 18px;color:#cdd6f4;font-size:15px;line-height:1.75;">',
        '<ul>' => '<ul style="margin:0 0 18px;padding-left:22px;color:#cdd6f4;font-size:15px;line-height:1.75;">',
        '<ol>' => '<ol style="margin:0 0 18px;padding-left:22px;color:#cdd6f4;font-size:15px;line-height:1.75;">',
        '<blockquote>' => '<blockquote style="margin:20px 0;padding:14px 18px;border-left:4px solid #89b4fa;background:#45475a;color:#cdd6f4;">',
        '<pre>' => '<pre style="margin:20px 0;padding:16px;overflow:auto;background:#181825;color:#cdd6f4;font-size:13px;line-height:1.6;">',
        '<code>' => '<code style="font-family:Consolas,monospace;color:#f9e2af;">',
        '<hr>' => '<hr style="margin:28px 0;border:0;border-top:1px solid #585b70;">',
        '<img ' => '<img style="display:block;max-width:100%;height:auto;margin:20px auto;" ',
    ];
    $html = str_replace(array_keys($styles), array_values($styles), $html);
    $html = preg_replace('~<a href="~', '<a style="color:#89b4fa;text-decoration:underline;" href="', $html) ?? $html;
    return preg_replace('~https://' . $domain . '/updates/images/~', 'https://' . $settings['domain'] . '/updates/images/', $html) ?? $html;
}

function update_newsletter_html(array $post, array $settings, bool $isTest = false): string
{
    $escape = 'feedback_mailer_escape';
    $title = $escape($post['title'] ?? 'Tile Image Generator update');
    $excerpt = $escape($post['excerpt'] ?? 'A new Tile Image Generator update is available.');
    $date = strtotime((string) ($post['date'] ?? ''));
    $dateLabel = $escape($date === false ? (string) ($post['date'] ?? '') : date('j F Y', $date));
    $domain = $escape($settings['domain']);
    $logoUrl = $escape('https://' . $settings['domain'] . '/logo.png');
    $publicUrl = $escape(update_newsletter_public_url($post, $settings));
    $profileUrl = $escape('https://' . $settings['domain'] . '/profile');
    $content = update_newsletter_content_html($post, $settings);
    $testBanner = $isTest ? '<tr><td style="padding:9px 18px;background:#f9e2af;color:#181825;font-size:12px;font-weight:700;text-align:center;text-transform:uppercase;">Test email - not sent to subscribers</td></tr>' : '';

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="dark"><meta name="supported-color-schemes" content="dark"><title>' . $title . '</title></head>'
        . '<body style="margin:0;padding:0;background:#1e1e2e;color:#ffffff;font-family:Manrope,Segoe UI,Arial,sans-serif;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $excerpt . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#1e1e2e;"><tr><td align="center" style="padding:28px 12px;">'
        . '<table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;border-collapse:collapse;">' . $testBanner
        . '<tr><td style="padding:28px;border-bottom:4px solid #89b4fa;background:#45475a;text-align:center;">'
        . '<img src="' . $logoUrl . '" height="72" alt="Tile Image Generator" style="display:block;width:72px;height:72px;margin:0 auto 12px;border:0;">'
        . '<div style="color:#89b4fa;font-size:11px;font-weight:700;line-height:1.4;text-transform:uppercase;">Product update &middot; ' . $dateLabel . '</div>'
        . '<h1 style="margin:9px 0 0;color:#ffffff;font-size:29px;line-height:1.25;">' . $title . '</h1></td></tr>'
        . '<tr><td style="padding:32px 30px;background:#313244;">' . $content
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:30px auto 8px;"><tr><td style="background:#89b4fa;">'
        . '<a href="' . $publicUrl . '" style="display:inline-block;padding:14px 22px;color:#181825;font-size:14px;font-weight:700;text-decoration:none;border-radius:0.55rem;">Read on Tile Image Generator</a>'
        . '</td></tr></table></td></tr>'
        . '<tr><td style="padding:20px 26px;background:#45475a;color:#bac2de;font-size:11px;line-height:1.65;text-align:center;">'
        . 'You received this because you subscribed to Tile Image Generator marketing updates.<br>'
        . '<a href="' . $profileUrl . '" style="color:#89b4fa;text-decoration:underline;">Manage your email preferences</a><br>'
        . 'Tile Image Generator &middot; ' . $domain . '</td></tr></table></td></tr></table></body></html>';
}

function update_newsletter_text(array $post, array $settings, bool $isTest = false): string
{
    $content = (string) ($post['content_raw'] ?? '');
    $content = preg_replace('/!\[([^]]*)]\(([^)]+)\)/', '$1: $2', $content) ?? $content;
    $content = preg_replace('/\[([^]]+)]\(([^)]+)\)/', '$1 ($2)', $content) ?? $content;
    $content = preg_replace('/^(?:#{1,6}|>|[-*+] )\s*/m', '', $content) ?? $content;
    $content = str_replace(['**', '__', '`'], '', $content);
    $prefix = $isTest ? "TEST EMAIL - not sent to subscribers\n\n" : '';
    return $prefix . (string) $post['title'] . "\n" . date('j F Y', strtotime((string) $post['date'])) . "\n\n"
        . trim($content) . "\n\nRead online:\n" . update_newsletter_public_url($post, $settings) . "\n\n"
        . "You received this because you subscribed to Tile Image Generator marketing updates.\n"
        . 'Manage your email preferences: https://' . $settings['domain'] . '/profile';
}

function update_newsletter_send(array $post, string $recipient, string $recipientName = '', bool $isTest = false): void
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid test email address.');
    }
    if (($post['state'] ?? '') !== 'published') {
        throw new RuntimeException('Only published updates can be emailed.');
    }

    $settings = project_settings('tileimagegen');
    $mail = feedback_mailer_create();
    $mail->addAddress($recipient, $recipientName);
    $mail->addCustomHeader('List-Unsubscribe', '<https://' . $settings['domain'] . '/profile>');
    $mail->isHTML(true);
    $mail->Subject = ($isTest ? '[Test] ' : '') . (string) $post['title'] . ' | Tile Image Generator';
    $mail->Body = update_newsletter_html($post, $settings, $isTest);
    $mail->AltBody = update_newsletter_text($post, $settings, $isTest);
    $mail->send();
}

function update_newsletter_queue(PDO $db, array $post): int
{
    if (($post['state'] ?? '') !== 'published') {
        throw new RuntimeException('Only published updates can be queued.');
    }
    update_newsletter_install($db);
    $now = gmdate('Y-m-d H:i:s');
    $statement = $db->prepare("INSERT IGNORE INTO tig_update_newsletter_deliveries
        (post_filename, post_title, post_slug, recipient_email, consent_at, status, queued_at, updated_at)
        SELECT :filename, :title, :slug, email, consent_at, 'queued', :queued_at, :updated_at
        FROM tig_newsletter_subscribers WHERE subscribed = 1 AND consent_at IS NOT NULL");
    $statement->execute([
        ':filename' => $post['filename'],
        ':title' => $post['title'],
        ':slug' => $post['slug'],
        ':queued_at' => $now,
        ':updated_at' => $now,
    ]);
    return $statement->rowCount();
}

function update_newsletter_run(int $limit = 25): array
{
    $db = update_newsletter_db();
    update_newsletter_install($db);
    $staleBefore = gmdate('Y-m-d H:i:s', time() - 900);
    $db->prepare("UPDATE tig_update_newsletter_deliveries SET status = 'queued' WHERE status = 'sending' AND updated_at < :stale_before")
        ->execute([':stale_before' => $staleBefore]);
    $limit = max(1, min(100, $limit));
    $deliveries = $db->query("SELECT * FROM tig_update_newsletter_deliveries WHERE status IN ('queued', 'failed') AND attempts < 5 ORDER BY id LIMIT {$limit}")->fetchAll();
    $summary = ['sent' => 0, 'failed' => 0, 'cancelled' => 0];

    foreach ($deliveries as $delivery) {
        $now = gmdate('Y-m-d H:i:s');
        $claim = $db->prepare("UPDATE tig_update_newsletter_deliveries SET status = 'sending', attempts = attempts + 1, updated_at = :updated_at WHERE id = :id AND status IN ('queued', 'failed')");
        $claim->execute([':updated_at' => $now, ':id' => $delivery['id']]);
        if ($claim->rowCount() !== 1) continue;

        $consent = $db->prepare('SELECT consent_at FROM tig_newsletter_subscribers WHERE email = :email AND subscribed = 1 AND consent_at IS NOT NULL');
        $consent->execute([':email' => $delivery['recipient_email']]);
        if ($consent->fetchColumn() === false) {
            $db->prepare("UPDATE tig_update_newsletter_deliveries SET status = 'cancelled', updated_at = :updated_at, last_error = 'Marketing consent withdrawn' WHERE id = :id")
                ->execute([':updated_at' => $now, ':id' => $delivery['id']]);
            $summary['cancelled']++;
            continue;
        }

        try {
            $post = updates_find_post((string) $delivery['post_filename']);
            if ($post === null) throw new RuntimeException('The update post no longer exists.');
            $name = $db->prepare('SELECT display_name FROM tig_feedback_users WHERE email = :email ORDER BY updated_at DESC LIMIT 1');
            $name->execute([':email' => $delivery['recipient_email']]);
            update_newsletter_send($post, (string) $delivery['recipient_email'], (string) ($name->fetchColumn() ?: ''));
            $db->prepare("UPDATE tig_update_newsletter_deliveries SET status = 'sent', sent_at = :sent_at, updated_at = :updated_at, last_error = NULL WHERE id = :id")
                ->execute([':sent_at' => $now, ':updated_at' => $now, ':id' => $delivery['id']]);
            $summary['sent']++;
        } catch (Throwable $error) {
            $message = mb_substr($error->getMessage(), 0, 1000);
            $db->prepare("UPDATE tig_update_newsletter_deliveries SET status = 'failed', updated_at = :updated_at, last_error = :error WHERE id = :id")
                ->execute([':updated_at' => $now, ':error' => $message, ':id' => $delivery['id']]);
            $summary['failed']++;
        }
    }
    return $summary;
}