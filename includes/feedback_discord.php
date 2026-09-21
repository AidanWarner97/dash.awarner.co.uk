<?php

declare(strict_types=1);

require_once __DIR__ . '/project_settings.php';

function feedback_discord_configured(): bool
{
    return trim((string) (getenv('TILEIMAGEGEN_DISCORD_WEBHOOK_URL') ?: '')) !== '';
}

function feedback_discord_limit(mixed $value, int $length): string
{
    $text = trim((string) $value);
    return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length - 1) . '…';
}

function feedback_discord_payload(array $feedback): array
{
    $settings = project_settings('tileimagegen');
    $publicUrl = 'https://' . $settings['domain'] . '/feedback/' . (int) ($feedback['public_id'] ?? 0);
    $name = trim((string) ($feedback['first_name'] ?? '') . ' ' . (string) ($feedback['last_name'] ?? ''));
    if ($name === '') $name = trim((string) ($feedback['name'] ?? 'Anonymous'));

    $timestamp = strtotime((string) ($feedback['created_at'] ?? ''));
    return [
        'username' => 'Tile Image Gen Feedback',
        'avatar_url' => 'https://' . $settings['domain'] . '/logo.png',
        'allowed_mentions' => ['parse' => []],
        'embeds' => [[
            'title' => 'Tile Image Generator - Feedback',
            'description' => '**Feedback Form Submission**',
            'url' => $publicUrl,
            'color' => 9024762,
            'thumbnail' => ['url' => 'https://' . $settings['domain'] . '/logo.png'],
            'fields' => [
                ['name' => 'Name', 'value' => feedback_discord_limit($name, 1024) ?: 'Not provided', 'inline' => false],
                ['name' => 'Email', 'value' => feedback_discord_limit($feedback['email'] ?? '', 1024) ?: 'Not provided', 'inline' => false],
                ['name' => "What's your feedback regarding?", 'value' => feedback_discord_limit(ucfirst((string) ($feedback['feedback_type'] ?? 'general')), 1024), 'inline' => false],
                ['name' => 'Your feedback', 'value' => feedback_discord_limit($feedback['message'] ?? '', 1024) ?: 'No message provided', 'inline' => false],
                ['name' => 'Reference', 'value' => '[Feedback #' . (int) ($feedback['public_id'] ?? 0) . '](' . $publicUrl . ')', 'inline' => false],
            ],
            'timestamp' => gmdate(DATE_ATOM, $timestamp === false ? time() : $timestamp),
            'footer' => ['text' => feedback_discord_limit($feedback['subject'] ?? 'Feedback submission', 2048)],
        ]],
    ];
}

function feedback_discord_reply_payload(array $feedback, array $reply): array
{
    $settings = project_settings('tileimagegen');
    $publicUrl = 'https://' . $settings['domain'] . '/feedback/' . (int) ($feedback['public_id'] ?? 0);
    $name = trim((string) ($feedback['first_name'] ?? '') . ' ' . (string) ($feedback['last_name'] ?? ''));
    if ($name === '') $name = trim((string) ($feedback['name'] ?? 'Anonymous'));

    return [
        'username' => 'Tile Image Gen Feedback',
        'avatar_url' => 'https://' . $settings['domain'] . '/logo.png',
        'allowed_mentions' => ['parse' => []],
        'embeds' => [[
            'title' => 'Tile Image Generator - Feedback',
            'description' => '**Email Reply Received**',
            'url' => $publicUrl,
            'color' => 10805921,
            'thumbnail' => ['url' => 'https://' . $settings['domain'] . '/logo.png'],
            'fields' => [
                ['name' => 'From', 'value' => feedback_discord_limit($name . ' <' . (string) ($feedback['email'] ?? '') . '>', 1024), 'inline' => false],
                ['name' => 'Feedback', 'value' => '[#' . (int) ($feedback['public_id'] ?? 0) . ' - ' . feedback_discord_limit($feedback['subject'] ?? 'Feedback', 900) . '](' . $publicUrl . ')', 'inline' => false],
                ['name' => 'Reply', 'value' => feedback_discord_limit($reply['body'] ?? '', 1024) ?: 'No message provided', 'inline' => false],
            ],
            'timestamp' => gmdate(DATE_ATOM, strtotime((string) ($reply['received_at'] ?? 'now')) ?: time()),
        ]],
    ];
}

function feedback_discord_send(array $feedback): void
{
    feedback_discord_send_payload(feedback_discord_payload($feedback));
}

function feedback_discord_send_reply(array $feedback, array $reply): void
{
    feedback_discord_send_payload(feedback_discord_reply_payload($feedback, $reply));
}

function feedback_discord_send_payload(array $payload): void
{
    if (!feedback_discord_configured()) throw new RuntimeException('The Discord webhook is not configured.');

    $webhookUrl = trim((string) getenv('TILEIMAGEGEN_DISCORD_WEBHOOK_URL'));
    if (filter_var($webhookUrl, FILTER_VALIDATE_URL) === false || parse_url($webhookUrl, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('The Discord webhook URL must be a valid HTTPS URL.');
    }

    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $request = curl_init($webhookUrl);
    if ($request === false) throw new RuntimeException('The Discord webhook request could not be initialized.');

    curl_setopt_array($request, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $error = curl_error($request);
    curl_close($request);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? 'The Discord webhook request failed.' : 'Discord rejected the webhook request with HTTP ' . $status . '.');
    }
}