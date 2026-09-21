<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/tileimagegen.php';

$requestedPeriod = (int) ($_GET['period'] ?? 7);
$data = tileimagegen_overview_data($requestedPeriod);
$settings = project_settings('tileimagegen');
$errors = array_values(array_filter([$data['usage']['error'], $data['updates']['error'], $data['feedback']['error']]));
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

dashboard_header($settings['title'] . ': Overview', 'tile-overview', 'tileimagegen');
?>
<section class="view active">
    <div class="section-title project-title">
        <span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1>PROJECT OVERVIEW</h1></span>
        <div class="title-actions"><form method="get"><label class="period-select">Period<select name="period" aria-label="Analytics period" onchange="this.form.submit()"><?php foreach ([7, 30, 90] as $period): ?><option value="<?= $period ?>"<?= $data['period']['days'] === $period ? ' selected' : '' ?>>Last <?= $period ?> days</option><?php endforeach; ?></select></label></form><a class="secondary-button" href="https://<?= $escape($settings['domain']) ?>" target="_blank" rel="noreferrer"><i data-lucide="external-link"></i>Visit site</a></div>
    </div>
    <?php if ($errors): ?>
        <div class="data-notice"><i data-lucide="circle-alert"></i><div><strong>Some live data is unavailable</strong><p><?= $escape(implode(' ', $errors)) ?></p></div></div>
    <?php endif; ?>
    <div class="overview-metrics" aria-label="<?= $escape($settings['title']) ?> summary">
        <article><span class="summary-icon"><i data-lucide="images"></i></span><div><small>GENERATIONS</small><strong><?= $data['usage']['available'] ? number_format($data['usage']['requests']) : '—' ?></strong><p>Last <?= $data['period']['days'] ?> days</p></div></article>
        <article><span class="summary-icon"><i data-lucide="message-square-dot"></i></span><div><small>NEW FEEDBACK</small><strong><?= $data['feedback']['available'] ? $data['feedback']['new'] : '—' ?></strong><p>Awaiting review</p></div></article>
        <article><span class="summary-icon"><i data-lucide="messages-square"></i></span><div><small>OPEN FEEDBACK</small><strong><?= $data['feedback']['available'] ? $data['feedback']['open'] : '—' ?></strong><p>In progress</p></div></article>
        <article><span class="summary-icon"><i data-lucide="newspaper"></i></span><div><small>PUBLISHED UPDATES</small><strong><?= $data['updates']['available'] ? $data['updates']['published'] : '—' ?></strong><p>All time</p></div></article>
    </div>
    <div class="tilegen-grid">
        <article class="content-card overview-panel usage-panel"><div class="card-heading"><div><small>LAST <?= $data['period']['days'] ?> DAYS</small><h2>DOWNLOADS</h2></div><div class="usage-totals"><span><strong><?= number_format($data['usage']['downloads']) ?></strong><small>CURRENT</small></span><span><strong><?= number_format($data['usage']['previous_downloads']) ?></strong><small>PREVIOUS</small></span></div></div><?php if ($data['usage']['available']): ?><div class="usage-chart"><canvas id="downloads-chart" aria-label="Daily downloads compared with the previous period" role="img"></canvas></div><div class="usage-requests"><div class="subheading"><span><small>REQUESTS</small><h3>RECENT GENERATIONS</h3></span><a class="text-button" href="/tileimagegen/logs/">View logs <i data-lucide="arrow-right"></i></a></div><?php if (!$data['usage']['recent']): ?><div class="list-message">No generation requests in this period.</div><?php endif; ?><?php foreach ($data['usage']['recent'] as $request): ?><a class="usage-request" href="/tileimagegen/logs/?id=<?= $escape($request['id']) ?>"><span class="log-state log-state-<?= $escape($request['status']) ?>"><i data-lucide="<?= $request['status'] === 'generated' ? 'circle-check' : 'circle-alert' ?>"></i></span><span><strong><?= $escape($request['tile_name']) ?></strong><small><?= $escape($request['layout']) ?> · <?= $escape(date('j M Y, H:i', strtotime((string) $request['created_at']))) ?></small></span><span class="usage-request-state"><i data-lucide="<?= $request['downloaded'] ? 'download' : 'minus' ?>"></i><?= $request['downloaded'] ? 'Downloaded' : 'Not downloaded' ?></span><i data-lucide="chevron-right"></i></a><?php endforeach; ?></div><?php else: ?><div class="unavailable-state"><span><i data-lucide="chart-no-axes-combined"></i></span><h3>Analytics unavailable</h3><p><?= $escape($data['usage']['error']) ?></p></div><?php endif; ?></article>
        <article class="content-card overview-panel"><div class="card-heading"><div><small>INBOX</small><h2>RECENT FEEDBACK</h2></div><a class="text-button" href="/tileimagegen/feedback/">View all <i data-lucide="arrow-right"></i></a></div><div class="overview-list">
            <?php if (!$data['feedback']['recent']): ?><div class="list-message">No feedback found.</div><?php endif; ?>
            <?php foreach ($data['feedback']['recent'] as $item): ?><div class="overview-row"><span><strong><?= $escape($item['subject']) ?></strong><small><?= $escape($item['category']) ?> · <?= $escape($item['created_at']) ?></small></span><span class="status-badge"><?= $escape($item['status']) ?></span></div><?php endforeach; ?>
        </div></article>
        <article class="content-card overview-panel updates-panel"><div class="card-heading"><div><small>CHANGELOG</small><h2>LATEST UPDATES</h2></div><a class="text-button" href="/tileimagegen/updates/">Manage updates <i data-lucide="arrow-right"></i></a></div><div class="overview-list">
            <?php if (!$data['updates']['recent']): ?><div class="list-message">No updates found.</div><?php endif; ?>
            <?php foreach ($data['updates']['recent'] as $item): ?><div class="overview-row"><span><strong><?= $escape($item['title']) ?></strong><small><?= $escape($item['author']) ?></small></span><time class="row-meta"><?= $escape($item['date']) ?></time></div><?php endforeach; ?>
        </div></article>
    </div>
</section>
<?php if ($data['usage']['available']): ?><script id="downloads-chart-data" type="application/json"><?= json_encode(['current' => $data['usage']['current'], 'previous' => $data['usage']['previous']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script><?php endif; ?>
<?php dashboard_footer(); ?>