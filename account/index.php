<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/layout.php';

$user = auth_current_user();
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	try {
		if (!auth_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('This session expired. Refresh and try again.');
		$action = (string) ($_POST['action'] ?? '');
		if ($action === 'add_user') {
			if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['password_confirmation'] ?? '')) throw new InvalidArgumentException('The passwords do not match.');
			auth_create_user((string) ($_POST['display_name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
			header('Location: /account/?administrator=added');
			exit;
		}
		if ($action === 'password') {
			if ((string) ($_POST['new_password'] ?? '') !== (string) ($_POST['password_confirmation'] ?? '')) throw new InvalidArgumentException('The new passwords do not match.');
			auth_change_password((string) $user['id'], (string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''));
			header('Location: /account/?password=changed');
			exit;
		}
		if ($action === 'delete_passkey') {
			auth_delete_passkey((string) $user['id'], (int) ($_POST['passkey_id'] ?? 0));
			header('Location: /account/?passkey=deleted');
			exit;
		}
		throw new InvalidArgumentException('Select a valid account action.');
	} catch (Throwable $exception) {
		$error = $exception->getMessage();
	}
}

$users = auth_list_users();
$passkeys = auth_passkeys_for_user((string) $user['id']);
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
dashboard_header('Account');
?>
<section class="view active">
	<div class="section-title project-title"><span><small>ACCOUNT</small><h1>SECURITY & ACCESS</h1></span><p><?= $escape($user['email']) ?></p></div>
	<?php if (isset($_GET['setup'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Administrator created. Add a passkey for faster sign-in.</div><?php endif; ?>
	<?php if (isset($_GET['administrator'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Administrator added.</div><?php endif; ?>
	<?php if (isset($_GET['password'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Password updated.</div><?php endif; ?>
	<?php if (isset($_GET['passkey'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Passkey <?= $_GET['passkey'] === 'deleted' ? 'removed' : 'added' ?>.</div><?php endif; ?>
	<?php if ($error): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($error) ?></div><?php endif; ?>
	<div class="security-grid">
		<article class="content-card security-card"><div class="card-heading"><div><small>SIGN-IN</small><h2>PASSKEYS</h2></div><i data-lucide="key-round"></i></div><div class="security-content"><p>Use your device, fingerprint, face, or security key to sign in without entering a password.</p><div class="security-list"><?php if (!$passkeys): ?><div class="list-message">No passkeys registered.</div><?php endif; ?><?php foreach ($passkeys as $passkey): ?><div class="security-row"><span><strong><?= $escape($passkey['label']) ?></strong><small>Added <?= $escape(date('j M Y', strtotime((string) $passkey['created_at']))) ?><?= $passkey['last_used_at'] ? ' · Last used ' . $escape(date('j M Y', strtotime((string) $passkey['last_used_at']))) : '' ?></small></span><form method="post"><input type="hidden" name="csrf_token" value="<?= $escape(auth_csrf_token()) ?>"><input type="hidden" name="action" value="delete_passkey"><input type="hidden" name="passkey_id" value="<?= (int) $passkey['id'] ?>"><button class="icon-button" type="submit" title="Remove passkey" aria-label="Remove <?= $escape($passkey['label']) ?>"><i data-lucide="trash-2"></i></button></form></div><?php endforeach; ?></div><label>Passkey name<input id="passkey-label" maxlength="100" value="My passkey"></label><button class="primary-button" type="button" data-passkey-register data-csrf="<?= $escape(auth_csrf_token()) ?>"><i data-lucide="key-round"></i>Add passkey</button><p class="auth-message" role="status" aria-live="polite"></p></div></article>
		<article class="content-card security-card"><div class="card-heading"><div><small>CREDENTIALS</small><h2>CHANGE PASSWORD</h2></div><i data-lucide="lock-keyhole"></i></div><form class="security-content auth-form" method="post"><input type="hidden" name="csrf_token" value="<?= $escape(auth_csrf_token()) ?>"><input type="hidden" name="action" value="password"><label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label><label>New password<input type="password" name="new_password" autocomplete="new-password" minlength="12" required></label><label>Confirm new password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label><button class="primary-button" type="submit"><i data-lucide="save"></i>Update password</button></form></article>
		<article class="content-card security-card security-wide"><div class="card-heading"><div><small><?= count($users) ?> ACCOUNTS</small><h2>ADMINISTRATORS</h2></div><i data-lucide="users"></i></div><div class="security-content"><div class="security-list"><?php foreach ($users as $administrator): ?><div class="security-row"><span><strong><?= $escape($administrator['display_name']) ?><?= $administrator['id'] === $user['id'] ? ' (you)' : '' ?></strong><small><?= $escape($administrator['email']) ?> · Added <?= $escape(date('j M Y', strtotime((string) $administrator['created_at']))) ?></small></span><span class="status-badge"><?= !empty($administrator['active']) ? 'Active' : 'Disabled' ?></span></div><?php endforeach; ?></div><form class="auth-form inline-account-form" method="post"><input type="hidden" name="csrf_token" value="<?= $escape(auth_csrf_token()) ?>"><input type="hidden" name="action" value="add_user"><label>Name<input name="display_name" maxlength="100" required></label><label>Email<input type="email" name="email" autocomplete="off" maxlength="254" required></label><label>Temporary password<input type="password" name="password" autocomplete="new-password" minlength="12" required></label><label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label><button class="primary-button" type="submit"><i data-lucide="user-plus"></i>Add administrator</button></form></div></article>
	</div>
</section>
<script src="/auth/auth.js" defer></script>
<?php dashboard_footer(); ?>