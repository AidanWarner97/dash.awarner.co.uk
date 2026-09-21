<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/update_manager.php';

updates_start_session();

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	try {
		if (!updates_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
			throw new RuntimeException('This form session has expired. Refresh the page and try again.');
		}

		if (($_POST['action'] ?? 'save') === 'delete') {
			updates_delete_post((string) ($_POST['filename'] ?? ''));
			header('Location: /tileimagegen/updates/?deleted=1');
			exit;
		}

		$filename = updates_save_post($_POST);
		header('Location: /tileimagegen/updates/?saved=1&edit=' . rawurlencode($filename));
		exit;
	} catch (Throwable $exception) {
		$error = $exception->getMessage();
	}
}

try {
	$posts = updates_all_posts();
} catch (Throwable $exception) {
	$posts = [];
	$error ??= $exception->getMessage();
}

$editing = null;
$editFilename = basename((string) ($_GET['edit'] ?? ''));
if ($editFilename !== '') {
	$editing = updates_find_post($editFilename);
	if ($editing === null) $error ??= 'The requested update could not be found.';
}

$showEditor = isset($_GET['new']) || $editing !== null || ($error !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
$form = [
	'original' => $editing['filename'] ?? '', 'title' => $editing['title'] ?? '', 'slug' => $editing['slug'] ?? '',
	'author' => $editing['author'] ?? 'Aidan Warner', 'state' => $editing['state'] ?? 'draft',
	'publish_at' => isset($editing['date']) ? date('Y-m-d', strtotime((string) $editing['date'])) : date('Y-m-d'),
	'content' => $editing['content_raw'] ?? '',
];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? 'save') === 'save') {
	foreach (array_keys($form) as $key) if (isset($_POST[$key])) $form[$key] = (string) $_POST[$key];
}

$settings = project_settings('tileimagegen');
dashboard_header($settings['title'] . ': Updates', 'tile-updates', 'tileimagegen');
?>
<section class="view active">
	<div class="section-title project-title">
		<span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1>UPDATES</h1></span>
		<div class="title-actions"><a class="secondary-button" href="https://<?= $escape($settings['domain']) ?>/updates/" target="_blank" rel="noreferrer"><i data-lucide="external-link"></i>View published</a><a class="primary-button" href="/tileimagegen/updates/?new=1"><i data-lucide="plus"></i>New update</a></div>
	</div>
	<?php if (isset($_GET['saved'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Update saved successfully.</div><?php endif; ?>
	<?php if (isset($_GET['deleted'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Update deleted successfully.</div><?php endif; ?>
	<?php if ($error !== null): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($error) ?></div><?php endif; ?>
	<?php if (!updates_writes_enabled()): ?><div class="data-notice"><i data-lucide="lock-keyhole"></i><div><strong>Update writing is disabled</strong><p>Add <code>DASHBOARD_ALLOW_WRITES=true</code> after administrator authentication is configured. Existing posts remain available to review.</p></div></div><?php endif; ?>

	<?php if ($showEditor): ?>
		<form class="content-card update-editor" method="post" action="/tileimagegen/updates/">
			<input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="original" value="<?= $escape($form['original']) ?>">
			<div class="card-heading"><div><small>EDITOR</small><h2><?= $editing ? 'EDIT UPDATE' : 'NEW UPDATE' ?></h2></div><a class="text-button" href="/tileimagegen/updates/">Close <i data-lucide="x"></i></a></div>
			<div class="editor-fields">
				<label class="wide">Title<input name="title" maxlength="180" required value="<?= $escape($form['title']) ?>" placeholder="Update title"></label>
				<label>Slug<input name="slug" value="<?= $escape($form['slug']) ?>" placeholder="generated-from-title"></label>
				<label>Author<input name="author" maxlength="100" required value="<?= $escape($form['author']) ?>"></label>
				<label>Publication state<select name="state" id="update-state"><option value="draft"<?= $form['state'] === 'draft' ? ' selected' : '' ?>>Draft</option><option value="published"<?= $form['state'] === 'published' ? ' selected' : '' ?>>Published</option><option value="scheduled"<?= $form['state'] === 'scheduled' ? ' selected' : '' ?>>Scheduled</option></select></label>
				<label id="publish-at-field">Publication date<input type="date" name="publish_at" value="<?= $escape($form['publish_at']) ?>" required></label>
				<label class="wide">Content <span>Markdown supported</span><textarea name="content" rows="18" required placeholder="Write the update in Markdown…"><?= $escape($form['content']) ?></textarea></label>
			</div>
			<div class="editor-actions"><a class="secondary-button" href="/tileimagegen/updates/">Cancel</a><button class="primary-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="save"></i>Save update</button></div>
		</form>
	<?php else: ?>
		<div class="content-card updates-manager">
			<div class="card-heading"><div><small><?= count($posts) ?> TOTAL</small><h2>ALL UPDATES</h2></div></div>
			<?php if (!$posts): ?><div class="list-message">No update posts found.</div><?php endif; ?>
			<div class="updates-table-wrap"><table class="updates-table"><thead><tr><th>Update</th><th>Status</th><th>Publication</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
				<?php foreach ($posts as $post): ?><tr>
					<td><strong><?= $escape($post['title']) ?></strong><small><?= $escape($post['author']) ?> · /<?= $escape($post['slug']) ?></small></td>
					<td><span class="status-badge status-<?= $escape($post['state']) ?>"><?= $escape($post['state']) ?></span></td>
					<td><time><?= $escape(date('j M Y', strtotime((string) $post['date']))) ?></time></td>
					<td class="table-actions"><a class="icon-button" href="/tileimagegen/updates/?edit=<?= rawurlencode($post['filename']) ?>" aria-label="Edit <?= $escape($post['title']) ?>" title="Edit"><i data-lucide="pencil"></i></a><form method="post" action="/tileimagegen/updates/"><input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="filename" value="<?= $escape($post['filename']) ?>"><button class="icon-button delete-update" type="submit" aria-label="Delete <?= $escape($post['title']) ?>" title="Delete"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="trash-2"></i></button></form></td>
				</tr><?php endforeach; ?>
			</tbody></table></div>
		</div>
	<?php endif; ?>
</section>
<?php dashboard_footer(); ?>