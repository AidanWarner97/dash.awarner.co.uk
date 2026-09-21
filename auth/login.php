<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

auth_send_security_headers();

if (!auth_has_users()) {
    header('Location: /auth/setup.php');
    exit;
}
if (auth_current_user() !== null) {
    header('Location: /');
    exit;
}

$error = null;
$returnPath = auth_safe_return_path((string) ($_GET['return'] ?? $_POST['return'] ?? '/'));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!auth_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('This sign-in session expired. Refresh and try again.');
        if (!auth_attempt_password((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) throw new InvalidArgumentException('The email or password is incorrect.');
        header('Location: ' . $returnPath);
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#45475a"><title>Sign in | Aidan Warner</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/style.css"><script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js" defer></script><script src="/auth/auth.js" defer></script></head>
<body class="auth-page"><main class="auth-shell"><section class="auth-brand"><span class="identity-mark">AW</span><small>PRIVATE ADMINISTRATION</small><h1>AIDAN WARNER</h1><p>Sign in to manage projects, infrastructure, feedback, and publishing.</p></section><section class="auth-panel"><div><small>WELCOME BACK</small><h2>Sign in</h2></div><?php if ($error): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($error) ?></div><?php endif; ?><form class="auth-form" method="post"><input type="hidden" name="csrf_token" value="<?= $escape(auth_csrf_token()) ?>"><input type="hidden" name="return" value="<?= $escape($returnPath) ?>"><label>Email<input type="email" name="email" autocomplete="username" maxlength="254" required value="<?= $escape($_POST['email'] ?? '') ?>"></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="primary-button" type="submit"><i data-lucide="log-in"></i>Sign in</button></form><div class="auth-divider"><span>or</span></div><button class="secondary-button full-button" type="button" data-passkey-login data-return="<?= $escape($returnPath) ?>"><i data-lucide="key-round"></i>Sign in with a passkey</button><p class="auth-message" role="status" aria-live="polite"></p></section></main></body></html>