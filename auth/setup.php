<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

auth_send_security_headers();

if (auth_has_users()) {
    header('Location: /auth/login.php');
    exit;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!auth_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('This setup session expired. Refresh and try again.');
        if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['password_confirmation'] ?? '')) throw new InvalidArgumentException('The passwords do not match.');
        $user = auth_create_initial_user((string) ($_POST['display_name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        auth_complete_login($user);
        header('Location: /account/?setup=1');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#45475a"><title>Set up dashboard | Aidan Warner</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/style.css"><script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js" defer></script><script src="/auth/auth.js" defer></script></head>
<body class="auth-page"><main class="auth-shell"><section class="auth-brand"><span class="identity-mark">AW</span><small>PRIVATE ADMINISTRATION</small><h1>AIDAN WARNER</h1><p>Create the first administrator account. This setup closes permanently once the account is saved.</p></section><section class="auth-panel"><div><small>INITIAL SETUP</small><h2>Create administrator</h2></div><?php if ($error): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($error) ?></div><?php endif; ?><form class="auth-form" method="post"><input type="hidden" name="csrf_token" value="<?= $escape(auth_csrf_token()) ?>"><label>Name<input name="display_name" autocomplete="name" maxlength="100" required value="<?= $escape($_POST['display_name'] ?? '') ?>"></label><label>Email<input type="email" name="email" autocomplete="email" maxlength="254" required value="<?= $escape($_POST['email'] ?? '') ?>"></label><label>Password<input type="password" name="password" autocomplete="new-password" minlength="12" required><small>At least 12 characters.</small></label><label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label><button class="primary-button" type="submit"><i data-lucide="user-round-check"></i>Create administrator</button></form></section></main></body></html>