<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    $mode = (string) ($payload['mode'] ?? '');
    $action = (string) ($payload['action'] ?? '');
    if (!in_array($mode, ['login', 'register'], true) || !in_array($action, ['options', 'verify'], true)) throw new InvalidArgumentException('Invalid passkey request.');

    $user = auth_current_user();
    if ($mode === 'register' && $user === null) {
        http_response_code(401);
        throw new RuntimeException('Sign in before adding a passkey.');
    }
    if ($mode === 'register' && !auth_verify_csrf((string) ($payload['csrf_token'] ?? ''))) throw new RuntimeException('This session expired. Refresh and try again.');

    if ($action === 'options') {
        $options = $mode === 'register' ? auth_passkey_registration_options($user) : auth_passkey_login_options();
        echo json_encode(['ok' => true, 'options' => $options], JSON_THROW_ON_ERROR);
        exit;
    }

    $credential = is_array($payload['credential'] ?? null) ? $payload['credential'] : [];
    if ($mode === 'register') {
        auth_register_passkey($user, $credential, (string) ($payload['label'] ?? 'My passkey'));
        echo json_encode(['ok' => true, 'redirect' => '/account/?passkey=added'], JSON_THROW_ON_ERROR);
    } else {
        if (!auth_attempt_passkey($credential)) throw new RuntimeException('This passkey could not be verified.');
        echo json_encode(['ok' => true, 'redirect' => auth_safe_return_path((string) ($payload['return'] ?? '/'))], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
}