<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/project_settings.php';

function feedback_mailer_configured(): bool
{
    return trim((string) (getenv('TILEIMAGEGEN_SMTP_HOST') ?: '')) !== ''
        && trim((string) (getenv('TILEIMAGEGEN_SMTP_FROM_EMAIL') ?: '')) !== '';
}

function feedback_mailer_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function feedback_mailer_reply_address(array $feedback): ?string
{
    $baseAddress = trim((string) (getenv('TILEIMAGEGEN_REPLY_EMAIL') ?: getenv('TILEIMAGEGEN_SMTP_FROM_EMAIL') ?: ''));
    return filter_var($baseAddress, FILTER_VALIDATE_EMAIL) ? $baseAddress : null;
}

function feedback_mailer_comment_html(array $feedback, string $comment, string $author, string $publicUrl, array $settings): string
{
    $firstName = feedback_mailer_escape($feedback['first_name'] ?? '');
    $subject = feedback_mailer_escape($feedback['subject'] ?? 'Your feedback');
    $commentHtml = nl2br(feedback_mailer_escape($comment));
    $authorName = feedback_mailer_escape($author);
    $url = feedback_mailer_escape($publicUrl);
    $domain = feedback_mailer_escape($settings['domain']);
    $logoUrl = feedback_mailer_escape('https://' . $settings['domain'] . '/logo.png');
    $feedbackId = (int) ($feedback['public_id'] ?? 0);

    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="dark"><meta name="supported-color-schemes" content="dark">'
        . '<title>Comment on your feedback</title></head>'
        . '<body style="margin:0;padding:0;background-color:#1e1e2e;color:#ffffff;font-family:Manrope,Segoe UI,Arial,sans-serif;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">A public comment was added to your Tile Image Generator feedback.</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:#1e1e2e;"><tr><td align="center" style="padding:28px 12px;">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;border-collapse:collapse;">'
        . '<tr><td style="padding:26px 28px 22px;border-bottom:4px solid #89b4fa;background-color:#45475a;text-align:center;">'
        . '<img src="' . $logoUrl . '" height="72" alt="Tile Image Generator" style="display:block;width:72px;height:72px;margin:0 auto 12px;border:0;outline:none;text-decoration:none;">'
        . '<h1 style="margin:6px 0 0;color:#ffffff;font-size:27px;font-weight:700;line-height:1.2;text-transform:uppercase;">Tile Image Generator</h1>'
        . '<p style="margin:8px 0 0;color:#bac2de;font-size:14px;line-height:1.5;">Feedback update</p></td></tr>'
        . '<tr><td style="padding:30px 28px;background-color:#313244;">'
        . '<p style="margin:0 0 16px;color:#ffffff;font-size:16px;line-height:1.65;">Hello ' . $firstName . ',</p>'
        . '<p style="margin:0 0 22px;color:#bac2de;font-size:15px;line-height:1.65;">A public comment has been added to your feedback.</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 18px;border:1px solid #6c7086;border-collapse:separate;">'
        . '<tr><td style="padding:14px 16px;background-color:#45475a;">'
        . '<div style="color:#89b4fa;font-size:11px;font-weight:700;line-height:1.4;text-transform:uppercase;">Feedback #' . $feedbackId . '</div>'
        . '<div style="margin-top:4px;color:#ffffff;font-size:16px;font-weight:700;line-height:1.45;">' . $subject . '</div></td></tr></table>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 24px;border-collapse:collapse;">'
        . '<tr><td width="4" style="width:4px;background-color:#89b4fa;font-size:0;line-height:0;">&nbsp;</td>'
        . '<td style="padding:18px 20px;background-color:#45475a;">'
        . '<div style="color:#ffffff;font-size:15px;line-height:1.7;">' . $commentHtml . '</div>'
        . '<div style="margin-top:14px;padding-top:12px;border-top:1px solid #6c7086;color:#bac2de;font-size:12px;line-height:1.5;">' . $authorName . ' &middot; Tile Image Generator</div>'
        . '</td></tr></table>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 22px;"><tr><td style="border:1px solid #7aa2e2;background-color:#89b4fa;">'
        . '<a href="' . $url . '" style="display:inline-block;padding:13px 20px;color:#181825;font-size:14px;font-weight:700;line-height:1;text-decoration:none;">View public discussion</a>'
        . '</td></tr></table>'
        . '<p style="margin:0;color:#bac2de;font-size:12px;line-height:1.6;text-align:center;">You received this email because you agreed to be contacted about this feedback.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 24px;background-color:#45475a;color:#bac2de;font-size:11px;line-height:1.5;text-align:center;">Tile Image Generator &middot; ' . $domain . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function feedback_mailer_comment_text(array $feedback, string $comment, string $author, string $publicUrl): string
{
    return 'Hello ' . (string) ($feedback['first_name'] ?? '') . ",\n\n"
        . 'A public comment has been added to your Tile Image Generator feedback #' . (int) ($feedback['public_id'] ?? 0) . ': '
        . (string) ($feedback['subject'] ?? 'Your feedback') . "\n\n"
        . $comment . "\n\n"
        . $author . " · Tile Image Generator\n\n"
        . "View the public discussion:\n" . $publicUrl . "\n\n"
        . 'You received this email because you agreed to be contacted about this feedback.';
}

function feedback_mailer_creation_html(array $feedback, string $publicUrl, array $settings): string
{
    $firstName = feedback_mailer_escape($feedback['first_name'] ?? '');
    $subject = feedback_mailer_escape($feedback['subject'] ?? 'Your feedback');
    $message = nl2br(feedback_mailer_escape($feedback['message'] ?? ''));
    $type = feedback_mailer_escape(ucfirst((string) ($feedback['feedback_type'] ?? 'general')));
    $url = feedback_mailer_escape($publicUrl);
    $domain = feedback_mailer_escape($settings['domain']);
    $logoUrl = feedback_mailer_escape('https://' . $settings['domain'] . '/logo.png');
    $feedbackId = (int) ($feedback['public_id'] ?? 0);

    return '<!doctype html>'
        . '<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="dark"><meta name="supported-color-schemes" content="dark">'
        . '<title>We received your feedback</title></head>'
        . '<body style="margin:0;padding:0;background-color:#1e1e2e;color:#ffffff;font-family:Manrope,Segoe UI,Arial,sans-serif;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">Your Tile Image Generator feedback has been received.</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:#1e1e2e;"><tr><td align="center" style="padding:28px 12px;">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;border-collapse:collapse;">'
        . '<tr><td style="padding:26px 28px 22px;border-bottom:4px solid #89b4fa;background-color:#45475a;text-align:center;">'
        . '<img src="' . $logoUrl . '" height="72" alt="Tile Image Generator" style="display:block;width:72px;height:72px;margin:0 auto 12px;border:0;outline:none;text-decoration:none;">'
        . '<h1 style="margin:6px 0 0;color:#ffffff;font-size:27px;font-weight:700;line-height:1.2;text-transform:uppercase;">Tile Image Generator</h1>'
        . '<p style="margin:8px 0 0;color:#bac2de;font-size:14px;line-height:1.5;">Feedback received</p></td></tr>'
        . '<tr><td style="padding:30px 28px;background-color:#313244;">'
        . '<p style="margin:0 0 16px;color:#ffffff;font-size:16px;line-height:1.65;">Hello ' . $firstName . ',</p>'
        . '<p style="margin:0 0 22px;color:#bac2de;font-size:15px;line-height:1.65;">Thanks for helping improve Tile Image Generator. Your feedback has been received with the following details:</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 18px;border:1px solid #6c7086;border-collapse:collapse;">'
        . '<tr><td style="padding:12px 16px;border-bottom:1px solid #6c7086;background-color:#45475a;color:#89b4fa;font-size:11px;font-weight:700;text-transform:uppercase;">Feedback #' . $feedbackId . '</td></tr>'
        . '<tr><td style="padding:14px 16px;border-bottom:1px solid #6c7086;background-color:#45475a;"><div style="color:#bac2de;font-size:11px;font-weight:700;text-transform:uppercase;">Title</div><div style="margin-top:4px;color:#ffffff;font-size:15px;font-weight:700;line-height:1.5;">' . $subject . '</div></td></tr>'
        . '<tr><td style="padding:14px 16px;background-color:#45475a;"><div style="color:#bac2de;font-size:11px;font-weight:700;text-transform:uppercase;">Regarding</div><div style="margin-top:4px;color:#ffffff;font-size:14px;line-height:1.5;">' . $type . '</div></td></tr></table>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 24px;border-collapse:collapse;"><tr>'
        . '<td width="4" style="width:4px;background-color:#89b4fa;font-size:0;line-height:0;">&nbsp;</td><td style="padding:18px 20px;background-color:#45475a;">'
        . '<div style="margin-bottom:8px;color:#89b4fa;font-size:11px;font-weight:700;text-transform:uppercase;">Your feedback</div><div style="color:#ffffff;font-size:15px;line-height:1.7;">' . $message . '</div></td></tr></table>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 22px;"><tr><td style="border:1px solid #7aa2e2;background-color:#89b4fa;">'
        . '<a href="' . $url . '" style="display:inline-block;padding:13px 20px;color:#181825;font-size:14px;font-weight:700;line-height:1;text-decoration:none;">View your feedback</a></td></tr></table>'
        . '<p style="margin:0;color:#bac2de;font-size:12px;line-height:1.6;text-align:center;">This is an automatic receipt for feedback submitted using your email address.</p>'
        . '</td></tr><tr><td style="padding:18px 24px;background-color:#45475a;color:#bac2de;font-size:11px;line-height:1.5;text-align:center;">Tile Image Generator &middot; ' . $domain . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function feedback_mailer_creation_text(array $feedback, string $publicUrl): string
{
    return 'Hello ' . (string) ($feedback['first_name'] ?? '') . ",\n\n"
        . "Thanks for helping improve Tile Image Generator. Your feedback has been received.\n\n"
        . 'Feedback #' . (int) ($feedback['public_id'] ?? 0) . "\n"
        . 'Title: ' . (string) ($feedback['subject'] ?? '') . "\n"
        . 'Regarding: ' . ucfirst((string) ($feedback['feedback_type'] ?? 'general')) . "\n\n"
        . "Your feedback:\n" . (string) ($feedback['message'] ?? '') . "\n\n"
        . "View your feedback:\n" . $publicUrl . "\n\n"
        . 'This is an automatic receipt for feedback submitted using your email address.';
}

function feedback_mailer_send_creation(array $feedback): void
{
    if (!feedback_mailer_configured()) throw new RuntimeException('SMTP is not configured.');

    $recipient = trim((string) ($feedback['email'] ?? ''));
    $fromEmail = trim((string) (getenv('TILEIMAGEGEN_SMTP_FROM_EMAIL') ?: ''));
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The SMTP sender or feedback recipient address is invalid.');

    $encryption = strtolower(trim((string) (getenv('TILEIMAGEGEN_SMTP_ENCRYPTION') ?: 'tls')));
    if (!in_array($encryption, ['tls', 'smtps', 'none'], true)) throw new RuntimeException('TILEIMAGEGEN_SMTP_ENCRYPTION must be tls, smtps, or none.');

    $settings = project_settings('tileimagegen');
    $publicUrl = 'https://' . $settings['domain'] . '/feedback/' . (int) $feedback['public_id'];
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = trim((string) getenv('TILEIMAGEGEN_SMTP_HOST'));
        $mail->Port = (int) (getenv('TILEIMAGEGEN_SMTP_PORT') ?: ($encryption === 'smtps' ? 465 : 587));
        $mail->Timeout = 10;
        $mail->SMTPAuth = trim((string) (getenv('TILEIMAGEGEN_SMTP_USERNAME') ?: '')) !== '';
        $mail->Username = (string) (getenv('TILEIMAGEGEN_SMTP_USERNAME') ?: '');
        $mail->Password = (string) (getenv('TILEIMAGEGEN_SMTP_PASSWORD') ?: '');
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        if ($encryption === 'tls') $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        elseif ($encryption === 'smtps') $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        else $mail->SMTPAutoTLS = false;

        $mail->setFrom($fromEmail, trim((string) (getenv('TILEIMAGEGEN_SMTP_FROM_NAME') ?: $settings['title'])));
        $mail->addAddress($recipient, trim((string) ($feedback['first_name'] ?? '') . ' ' . (string) ($feedback['last_name'] ?? '')));
        $replyAddress = feedback_mailer_reply_address($feedback);
        if ($replyAddress !== null) $mail->addReplyTo($replyAddress, 'Tile Image Generator Feedback');
        $mail->isHTML(true);
        $mail->Subject = 'We received your feedback #' . (int) $feedback['public_id'];
        $mail->Body = feedback_mailer_creation_html($feedback, $publicUrl, $settings);
        $mail->AltBody = feedback_mailer_creation_text($feedback, $publicUrl);
        $mail->send();
    } catch (MailerException $error) {
        throw new RuntimeException('The confirmation email could not be sent.', 0, $error);
    }
}

function feedback_mailer_send_response(array $feedback, string $response, string $author): void
{
    if (!feedback_mailer_configured()) {
        throw new RuntimeException('SMTP is not configured. Add the Tile Image Generator SMTP settings to .env.');
    }

    $recipient = trim((string) ($feedback['email'] ?? ''));
    $fromEmail = trim((string) (getenv('TILEIMAGEGEN_SMTP_FROM_EMAIL') ?: ''));
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The SMTP sender or feedback recipient address is invalid.');
    }

    $encryption = strtolower(trim((string) (getenv('TILEIMAGEGEN_SMTP_ENCRYPTION') ?: 'tls')));
    if (!in_array($encryption, ['tls', 'smtps', 'none'], true)) {
        throw new RuntimeException('TILEIMAGEGEN_SMTP_ENCRYPTION must be tls, smtps, or none.');
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = trim((string) getenv('TILEIMAGEGEN_SMTP_HOST'));
        $mail->Port = (int) (getenv('TILEIMAGEGEN_SMTP_PORT') ?: ($encryption === 'smtps' ? 465 : 587));
        $mail->Timeout = 10;
        $mail->SMTPAuth = trim((string) (getenv('TILEIMAGEGEN_SMTP_USERNAME') ?: '')) !== '';
        $mail->Username = (string) (getenv('TILEIMAGEGEN_SMTP_USERNAME') ?: '');
        $mail->Password = (string) (getenv('TILEIMAGEGEN_SMTP_PASSWORD') ?: '');
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        if ($encryption === 'tls') $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        elseif ($encryption === 'smtps') $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        else $mail->SMTPAutoTLS = false;

        $settings = project_settings('tileimagegen');
        $fromName = trim((string) (getenv('TILEIMAGEGEN_SMTP_FROM_NAME') ?: $settings['title']));
        $publicUrl = 'https://' . $settings['domain'] . '/feedback/' . (int) $feedback['public_id'];
        $recipientName = trim((string) ($feedback['first_name'] ?? '') . ' ' . (string) ($feedback['last_name'] ?? ''));

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($recipient, $recipientName);
        $replyAddress = feedback_mailer_reply_address($feedback);
        if ($replyAddress !== null) $mail->addReplyTo($replyAddress, 'Tile Image Generator Feedback');
        $mail->isHTML(true);
        $mail->Subject = 'Comment on your feedback #' . (int) $feedback['public_id'];
        $mail->Body = feedback_mailer_comment_html($feedback, $response, $author, $publicUrl, $settings);
        $mail->AltBody = feedback_mailer_comment_text($feedback, $response, $author, $publicUrl);
        $mail->send();
    } catch (MailerException $error) {
        throw new RuntimeException('The comment was published, but its notification email could not be sent.', 0, $error);
    }
}
