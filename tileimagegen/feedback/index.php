<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/feedback_manager.php';

updates_start_session();
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$error = null;
$publicId = max(0, (int) ($_GET['id'] ?? $_POST['public_id'] ?? 0));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	try {
		if (!updates_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
			throw new RuntimeException('This form session has expired. Refresh the page and try again.');
		}

		$action = (string) ($_POST['action'] ?? '');
		if ($action === 'status') feedback_management_update_status($publicId, (string) ($_POST['status'] ?? ''));
		elseif ($action === 'read') feedback_management_set_read($publicId, (string) ($_POST['read'] ?? '1') === '1');
		elseif ($action === 'note') feedback_management_add_note($publicId, (string) ($_POST['note'] ?? ''));
		elseif ($action === 'response') {
			$result = feedback_management_add_public_response($publicId, (string) ($_POST['response'] ?? ''));
			header('Location: /tileimagegen/feedback/?id=' . $publicId . '&delivery=' . rawurlencode($result['delivery']));
			exit;
		}
		else throw new InvalidArgumentException('Select a valid feedback action.');

		header('Location: /tileimagegen/feedback/?id=' . $publicId . '&saved=1');
		exit;
	} catch (Throwable $exception) {
		$error = $exception->getMessage();
	}
}

$filters = ['q' => (string) ($_GET['q'] ?? ''), 'status' => (string) ($_GET['status'] ?? 'all'), 'type' => (string) ($_GET['type'] ?? 'all'), 'read' => (string) ($_GET['read'] ?? 'all')];
$entry = null;
$feedback = [];
try {
	if ($publicId > 0) $entry = feedback_management_find($publicId);
	else $feedback = feedback_management_list($filters);
	if ($publicId > 0 && $entry === null) $error ??= 'Feedback entry not found.';
} catch (Throwable $exception) {
	$error ??= 'The feedback database could not be reached. Check the configured connection.';
}

$statuses = feedback_management_statuses();
$settings = project_settings('tileimagegen');
dashboard_header($settings['title'] . ': Feedback', 'tile-feedback', 'tileimagegen');
?>
<section class="view active">
	<div class="section-title project-title">
		<span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1><?= $entry ? 'FEEDBACK #' . $escape($entry['public_id']) : 'FEEDBACK' ?></h1></span>
		<div class="title-actions"><?php if ($entry): ?><a class="secondary-button" href="/tileimagegen/feedback/"><i data-lucide="arrow-left"></i>Back to inbox</a><?php endif; ?><a class="secondary-button" href="https://<?= $escape($settings['domain']) ?>/feedback" target="_blank" rel="noreferrer"><i data-lucide="external-link"></i>Public feedback</a></div>
	</div>
	<?php if (isset($_GET['saved'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Feedback updated successfully.</div><?php endif; ?>
	<?php if (isset($_GET['delivery'])): ?><?php $delivery = (string) $_GET['delivery']; ?><div class="flash <?= $delivery === 'failed' ? 'error' : 'success' ?>"><i data-lucide="<?= $delivery === 'failed' ? 'circle-alert' : 'circle-check' ?>"></i><?= $delivery === 'sent' ? 'Public comment added and emailed to the submitter.' : ($delivery === 'not_allowed' ? 'Public comment added. The submitter did not consent to email contact.' : 'Public comment added, but the notification email could not be sent. Check SMTP settings.') ?></div><?php endif; ?>
	<?php if ($error !== null): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($error) ?></div><?php endif; ?>
	<?php if (!updates_writes_enabled()): ?><div class="data-notice"><i data-lucide="lock-keyhole"></i><div><strong>Feedback changes are disabled</strong><p>Inbox browsing is read-only until dashboard authentication and <code>DASHBOARD_ALLOW_WRITES=true</code> are configured.</p></div></div><?php endif; ?>

	<?php if ($entry): ?>
		<div class="feedback-detail-grid">
			<div class="feedback-main-column">
			<article class="content-card feedback-message">
				<div class="card-heading"><div><small><?= $escape(strtoupper((string) $entry['feedback_type'])) ?></small><h2><?= $escape($entry['subject']) ?></h2></div><span class="status-badge status-<?= $escape($entry['status']) ?>"><?= $escape($statuses[$entry['status']] ?? $entry['status']) ?></span></div>
				<div class="message-meta"><span><small>FROM</small><strong><?= $escape(trim($entry['first_name'] . ' ' . $entry['last_name'])) ?></strong></span><span><small>EMAIL</small><a href="mailto:<?= $escape($entry['email']) ?>"><?= $escape($entry['email']) ?></a></span><span><small>RECEIVED</small><strong><?= $escape(date('j M Y, H:i', strtotime((string) $entry['created_at']))) ?></strong></span><span><small>CONTACT ALLOWED</small><strong><?= !empty($entry['contact_allowed']) ? 'Yes' : 'No' ?></strong></span></div>
				<div class="message-body"><?= nl2br($escape($entry['message'])) ?></div>
				<div class="public-responses"><div class="subheading"><span><small>PUBLIC</small><h3>COMMENTS</h3></span><span class="database-count"><?= count($entry['responses']) ?></span></div><?php if (!$entry['responses']): ?><div class="list-message">No public comments yet.</div><?php endif; ?><?php foreach ($entry['responses'] as $response): ?><div class="public-response"><p><?= nl2br($escape($response['body'])) ?></p><small><?= $escape($response['author']) ?> · <?= $escape(date('j M Y, H:i', strtotime((string) $response['created_at']))) ?><?php if ($response['emailed_at']): ?> · Email sent<?php elseif ($response['email_error']): ?> · Email failed<?php endif; ?></small></div><?php endforeach; ?></div>
				<div class="public-responses"><div class="subheading"><span><small>PRIVATE</small><h3>EMAIL REPLIES</h3></span><span class="database-count"><?= count($entry['email_replies']) ?></span></div><?php if (!$entry['email_replies']): ?><div class="list-message">No email replies yet.</div><?php endif; ?><?php foreach ($entry['email_replies'] as $reply): ?><div class="public-response"><p><?= nl2br($escape($reply['body'])) ?></p><small><?= $escape($reply['sender']) ?> · <?= $escape(date('j M Y, H:i', strtotime((string) $reply['received_at']))) ?> · Received by email</small></div><?php endforeach; ?></div>
			</article>
			<article class="content-card control-card public-comment-card"><div class="card-heading"><div><small>TRANSPARENCY</small><h2>ADD PUBLIC COMMENT</h2></div></div><form class="note-form" method="post" action="/tileimagegen/feedback/?id=<?= $escape($entry['public_id']) ?>"><input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="public_id" value="<?= $escape($entry['public_id']) ?>"><input type="hidden" name="action" value="response"><label><span class="visually-hidden">Public comment</span><textarea name="response" maxlength="10000" required placeholder="Write a comment visible on the public feedback page…"></textarea></label><p class="response-delivery"><i data-lucide="<?= !empty($entry['contact_allowed']) ? 'mail-check' : 'mail-x' ?>"></i><?= !empty($entry['contact_allowed']) ? (feedback_mailer_configured() ? 'The submitter consented to contact and will be emailed.' : 'The submitter consented, but SMTP is not configured yet.') : 'The submitter did not consent to email contact. The comment will only be published.' ?></p><button class="primary-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="send"></i>Publish comment</button></form></article>
			</div>

			<aside class="feedback-controls">
				<article class="content-card control-card"><div class="card-heading"><div><small>WORKFLOW</small><h2>MANAGE</h2></div></div><div class="control-content">
					<form method="post" action="/tileimagegen/feedback/?id=<?= $escape($entry['public_id']) ?>"><input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="public_id" value="<?= $escape($entry['public_id']) ?>"><input type="hidden" name="action" value="status"><label>Status<select name="status"><?php foreach ($statuses as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $entry['status'] === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label><button class="primary-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>>Update status</button></form>
					<form method="post" action="/tileimagegen/feedback/?id=<?= $escape($entry['public_id']) ?>"><input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="public_id" value="<?= $escape($entry['public_id']) ?>"><input type="hidden" name="action" value="read"><input type="hidden" name="read" value="<?= $entry['read_at'] ? '0' : '1' ?>"><button class="secondary-button full-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="<?= $entry['read_at'] ? 'mail' : 'mail-open' ?>"></i>Mark <?= $entry['read_at'] ? 'unread' : 'read' ?></button></form>
				</div></article>
				<article class="content-card control-card"><div class="card-heading"><div><small>PRIVATE</small><h2>ADMIN NOTES</h2></div></div><div class="notes-list"><?php if (!$entry['notes']): ?><div class="list-message">No private notes.</div><?php endif; ?><?php foreach ($entry['notes'] as $note): ?><div class="note"><p><?= nl2br($escape($note['note'])) ?></p><small><?= $escape($note['author']) ?> · <?= $escape(date('j M Y, H:i', strtotime((string) $note['created_at']))) ?></small></div><?php endforeach; ?></div><form class="note-form" method="post" action="/tileimagegen/feedback/?id=<?= $escape($entry['public_id']) ?>"><input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="public_id" value="<?= $escape($entry['public_id']) ?>"><input type="hidden" name="action" value="note"><label><span class="visually-hidden">Private note</span><textarea name="note" maxlength="5000" required placeholder="Add a private note…"></textarea></label><button class="primary-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>>Add note</button></form></article>
			</aside>
		</div>
		<?php if ($entry['audit']): ?><article class="content-card audit-card"><div class="card-heading"><div><small>HISTORY</small><h2>AUDIT LOG</h2></div></div><div class="overview-list"><?php foreach ($entry['audit'] as $event): ?><div class="overview-row"><span><strong><?= $escape(ucwords(str_replace('_', ' ', $event['action']))) ?></strong><small><?= $escape($event['actor']) ?><?php if ($event['old_value'] !== null): ?> · <?= $escape($event['old_value']) ?> → <?= $escape($event['new_value']) ?><?php endif; ?></small></span><time class="row-meta"><?= $escape(date('j M Y, H:i', strtotime((string) $event['created_at']))) ?></time></div><?php endforeach; ?></div></article><?php endif; ?>
	<?php elseif ($error === null): ?>
		<form class="feedback-filters" method="get" action="/tileimagegen/feedback/"><label class="search-field"><i data-lucide="search"></i><input type="search" name="q" value="<?= $escape($filters['q']) ?>" placeholder="Search feedback or email"></label><label><span class="visually-hidden">Status</span><select name="status"><option value="all">All statuses</option><?php foreach ($statuses as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label><label><span class="visually-hidden">Category</span><select name="type"><option value="all">All categories</option><option value="general"<?= $filters['type'] === 'general' ? ' selected' : '' ?>>General</option><option value="bug"<?= $filters['type'] === 'bug' ? ' selected' : '' ?>>Bug</option></select></label><label><span class="visually-hidden">Read state</span><select name="read"><option value="all">Read and unread</option><option value="unread"<?= $filters['read'] === 'unread' ? ' selected' : '' ?>>Unread</option><option value="read"<?= $filters['read'] === 'read' ? ' selected' : '' ?>>Read</option></select></label><button class="primary-button" type="submit">Filter</button></form>
		<div class="content-card feedback-inbox"><div class="card-heading"><div><small><?= count($feedback) ?> RESULTS</small><h2>INBOX</h2></div></div><?php if (!$feedback): ?><div class="list-message">No feedback matches these filters.</div><?php endif; ?><div class="feedback-list"><?php foreach ($feedback as $item): ?><a class="feedback-row<?= $item['read_at'] ? '' : ' unread' ?>" href="/tileimagegen/feedback/?id=<?= $escape($item['public_id']) ?>"><span class="read-dot"></span><span><strong><?= $escape($item['subject']) ?></strong><small><?= $escape(trim($item['first_name'] . ' ' . $item['last_name'])) ?> · <?= $escape($item['email']) ?></small></span><span class="feedback-category"><?= $escape($item['feedback_category']) ?> - <?= $escape($item['feedback_type']) ?></span><span class="status-badge status-<?= $escape($item['status']) ?>"><?= $escape($statuses[$item['status']] ?? $item['status']) ?></span><time><?= $escape(date('j M Y', strtotime((string) $item['created_at']))) ?></time><i data-lucide="chevron-right"></i></a><?php endforeach; ?></div></div>
	<?php endif; ?>
</section>
<?php dashboard_footer(); ?>