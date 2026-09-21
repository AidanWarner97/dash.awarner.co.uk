<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/generation_logs.php';

$settings = project_settings('tileimagegen');
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$filters = [
	'q' => (string) ($_GET['q'] ?? ''),
	'status' => (string) ($_GET['status'] ?? 'all'),
	'downloaded' => (string) ($_GET['downloaded'] ?? 'all'),
];
$selectedId = max(0, (int) ($_GET['id'] ?? 0));
$summary = ['total' => 0, 'generated' => 0, 'errors' => 0, 'downloaded' => 0];
$logs = [];
$selectedLog = null;
$error = null;

try {
	$database = project_database_connection('tileimagegen');
	$summary = generation_logs_summary($database);
	if ($selectedId > 0) {
		$selectedLog = generation_logs_find($database, $selectedId);
		if ($selectedLog === null) $error = 'Generation log entry not found.';
	} else {
		$logs = generation_logs_list($database, $filters);
	}
} catch (Throwable) {
	$error = 'Generation logs could not be loaded. Check the project database and table prefix settings.';
}

dashboard_header($settings['title'] . ': Logs', 'tile-logs', 'tileimagegen');
?>
<section class="view active">
	<div class="section-title project-title">
		<span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1><?= $selectedLog ? 'GENERATION #' . $escape($selectedLog['id']) : 'GENERATION LOGS' ?></h1></span>
		<div class="title-actions"><?php if ($selectedLog): ?><a class="secondary-button" href="/tileimagegen/logs/"><i data-lucide="arrow-left"></i>Back to logs</a><?php endif; ?><a class="secondary-button" href="/tileimagegen/database/?table=<?= rawurlencode(trim(project_database_table('tileimagegen', 'generate_log'), '`')) ?>"><i data-lucide="database"></i>View table</a></div>
	</div>
	<?php if ($error !== null): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($error) ?></div><?php endif; ?>

	<div class="log-summary">
		<span><small>TOTAL REQUESTS</small><strong><?= number_format($summary['total']) ?></strong></span>
		<span><small>GENERATED</small><strong><?= number_format($summary['generated']) ?></strong></span>
		<span><small>ERRORS</small><strong><?= number_format($summary['errors']) ?></strong></span>
		<span><small>DOWNLOADED</small><strong><?= number_format($summary['downloaded']) ?></strong></span>
	</div>

	<?php if ($selectedLog): ?>
		<article class="content-card log-detail">
			<div class="card-heading"><div><small><?= $escape(strtoupper($selectedLog['status'])) ?></small><h2><?= $escape($selectedLog['tile_name']) ?></h2></div><span class="status-badge log-status-<?= $escape($selectedLog['status']) ?>"><?= $escape($selectedLog['status']) ?></span></div>
			<dl class="log-metadata">
				<div><dt>Request ID</dt><dd><?= $escape($selectedLog['request_id']) ?></dd></div>
				<div><dt>Created</dt><dd><?= $escape(date('j M Y, H:i:s', strtotime((string) $selectedLog['created_at']))) ?></dd></div>
				<div><dt>IP address</dt><dd><?= $escape($selectedLog['ip']) ?></dd></div>
				<div><dt>Layout</dt><dd><?= $escape($selectedLog['layout']) ?></dd></div>
				<div><dt>Tile size</dt><dd><?= $escape($selectedLog['tile_size_width']) ?> × <?= $escape($selectedLog['tile_size_height']) ?> px</dd></div>
				<div><dt>Images</dt><dd><?= $escape($selectedLog['image_file_count']) ?></dd></div>
				<div><dt>Grout</dt><dd><i class="colour-swatch" style="background-color: <?= $escape($selectedLog['grout_colour']) ?>"></i><?= $escape($selectedLog['grout_colour']) ?> · <?= $escape($selectedLog['grout_size']) ?> px</dd></div>
				<div><dt>Downloaded</dt><dd><?= $selectedLog['downloaded'] ? 'Yes' : 'No' ?><?= $selectedLog['downloaded_at'] ? ' · ' . $escape(date('j M Y, H:i:s', strtotime((string) $selectedLog['downloaded_at']))) : '' ?></dd></div>
			</dl>
			<?php if ($selectedLog['error_message'] !== null && $selectedLog['error_message'] !== ''): ?><div class="log-error"><small>ERROR MESSAGE</small><pre><?= $escape($selectedLog['error_message']) ?></pre></div><?php endif; ?>
		</article>
	<?php elseif ($error === null): ?>
		<form class="log-filters" method="get" action="/tileimagegen/logs/">
			<label class="search-field"><i data-lucide="search"></i><input type="search" name="q" value="<?= $escape($filters['q']) ?>" placeholder="Search tile or request ID"></label>
			<label><span class="visually-hidden">Status</span><select name="status"><option value="all">All statuses</option><option value="generated"<?= $filters['status'] === 'generated' ? ' selected' : '' ?>>Generated</option><option value="error"<?= $filters['status'] === 'error' ? ' selected' : '' ?>>Errors</option></select></label>
			<label><span class="visually-hidden">Download state</span><select name="downloaded"><option value="all">All downloads</option><option value="1"<?= $filters['downloaded'] === '1' ? ' selected' : '' ?>>Downloaded</option><option value="0"<?= $filters['downloaded'] === '0' ? ' selected' : '' ?>>Not downloaded</option></select></label>
			<button class="primary-button" type="submit">Filter</button>
		</form>
		<article class="content-card log-list-card">
			<div class="card-heading"><div><small><?= count($logs) ?> RESULTS</small><h2>RECENT GENERATIONS</h2></div><span class="database-count">Newest 200</span></div>
			<div class="log-list"><?php if ($logs === []): ?><div class="list-message">No generation logs match these filters.</div><?php endif; ?><?php foreach ($logs as $log): ?><a class="log-row" href="?id=<?= $escape($log['id']) ?>"><span class="log-state log-state-<?= $escape($log['status']) ?>"><i data-lucide="<?= $log['status'] === 'generated' ? 'circle-check' : 'circle-alert' ?>"></i></span><span class="log-name"><strong><?= $escape($log['tile_name']) ?></strong><small><?= $escape($log['request_id']) ?></small></span><span><strong><?= $escape($log['layout']) ?></strong><small><?= $escape($log['tile_size_width']) ?> × <?= $escape($log['tile_size_height']) ?> px · <?= $escape($log['image_file_count']) ?> image<?= (int) $log['image_file_count'] === 1 ? '' : 's' ?></small></span><span class="status-badge log-status-<?= $escape($log['status']) ?>"><?= $escape($log['status']) ?></span><span class="log-download"><i data-lucide="<?= $log['downloaded'] ? 'download' : 'minus' ?>"></i><?= $log['downloaded'] ? 'Downloaded' : 'Not downloaded' ?></span><time><?= $escape(date('j M Y, H:i', strtotime((string) $log['created_at']))) ?></time><i data-lucide="chevron-right"></i></a><?php endforeach; ?></div>
		</article>
	<?php endif; ?>
</section>
<?php
dashboard_footer();