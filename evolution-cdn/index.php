<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/evolutioncdn.php';

$settings = project_settings('evolutioncdn');
$data = evolutioncdn_overview_data((int) ($_GET['period'] ?? 7));
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$errors = array_values(array_unique(array_filter([
    $data['downloads']['error'], $data['requests']['error'], $data['queue']['error'], $data['source']['error'],
])));

dashboard_header($settings['title'] . ': Overview', 'evolution-overview', 'evolutioncdn');
?>
<section class="view active">
    <div class="section-title project-title">
        <span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1>CDN OVERVIEW</h1></span>
        <div class="title-actions"><form method="get"><label class="period-select">Period<select name="period" aria-label="Analytics period" onchange="this.form.submit()"><?php foreach ([7, 30, 90] as $period): ?><option value="<?= $period ?>"<?= $data['period'] === $period ? ' selected' : '' ?>>Last <?= $period ?> days</option><?php endforeach; ?></select></label></form><a class="secondary-button" href="https://<?= $escape($settings['domain']) ?>" target="_blank" rel="noreferrer"><i data-lucide="external-link"></i>Open CDN</a></div>
    </div>
    <?php if ($errors): ?><div class="data-notice"><i data-lucide="circle-alert"></i><div><strong>Some live data is unavailable</strong><p><?= $escape(implode(' ', $errors)) ?></p></div></div><?php endif; ?>

    <div class="overview-metrics" aria-label="Evolution X CDN summary">
        <article><span class="summary-icon"><i data-lucide="download"></i></span><div><small>DOWNLOADS</small><strong><?= $data['downloads']['available'] ? number_format($data['downloads']['period']) : '—' ?></strong><p>Last <?= $data['period'] ?> days</p></div></article>
        <article><span class="summary-icon"><i data-lucide="activity"></i></span><div><small>REQUESTS</small><strong><?= $data['requests']['available'] ? number_format($data['requests']['period']) : '—' ?></strong><p>Last <?= $data['period'] ?> days</p></div></article>
        <article><span class="summary-icon"><i data-lucide="package-open"></i></span><div><small>RELEASE QUEUE</small><strong><?= $data['queue']['available'] ? number_format($data['queue']['pending']) : '—' ?></strong><p>Queued or processing</p></div></article>
        <article><span class="summary-icon"><i data-lucide="shield-alert"></i></span><div><small>RATE LIMITED</small><strong><?= $data['requests']['available'] ? number_format($data['requests']['limited']) : '—' ?></strong><p>Last <?= $data['period'] ?> days</p></div></article>
    </div>

    <div class="tilegen-grid evolution-grid">
        <article class="content-card overview-panel"><div class="card-heading"><div><small>DELIVERY PIPELINE</small><h2>RECENT RELEASES</h2></div><a class="text-button" href="/evolution-cdn/database/?table=push_release_queue">View table <i data-lucide="arrow-right"></i></a></div><div class="overview-list">
            <?php if (!$data['queue']['recent']): ?><div class="list-message">No release jobs are available.</div><?php endif; ?>
            <?php foreach ($data['queue']['recent'] as $job): ?><div class="overview-row"><span><strong><?= $escape($job['codename']) ?> · <?= $escape($job['version']) ?></strong><small><?= $escape($job['build_type']) ?> · <?= $escape(date('j M Y, H:i', strtotime((string) $job['created_at']))) ?></small></span><span class="status-badge status-<?= $escape($job['status']) ?>"><?= $escape($job['status']) ?></span></div><?php endforeach; ?>
        </div></article>
        <article class="content-card overview-panel"><div class="card-heading"><div><small><?= number_format($data['downloads']['all_time']) ?> ALL TIME</small><h2>TOP DOWNLOAD FOLDERS</h2></div><a class="text-button" href="https://<?= $escape($settings['domain']) ?>/stats" target="_blank" rel="noreferrer">Public stats <i data-lucide="external-link"></i></a></div><div class="overview-list">
            <?php if (!$data['downloads']['top']): ?><div class="list-message">No download statistics are available.</div><?php endif; ?>
            <?php foreach ($data['downloads']['top'] as $device): ?><div class="overview-row"><span><strong><?= $escape($device['device']) ?></strong><small>Download folder</small></span><span class="row-meta"><?= number_format((int) $device['total']) ?></span></div><?php endforeach; ?>
        </div></article>
        <article class="content-card overview-panel"><div class="card-heading"><div><small>APPLICATION</small><h2>SERVICE SURFACES</h2></div><i data-lucide="server-cog"></i></div><div class="overview-list evolution-services">
            <a class="overview-row" href="https://<?= $escape($settings['domain']) ?>/health" target="_blank" rel="noreferrer"><span><strong>Health</strong><small>Jenkins, storage, downloads, and queue</small></span><i data-lucide="external-link"></i></a>
            <a class="overview-row" href="https://<?= $escape($settings['domain']) ?>/list" target="_blank" rel="noreferrer"><span><strong>File listing</strong><small>Published OTA release browser</small></span><i data-lucide="external-link"></i></a>
            <div class="overview-row"><span><strong>Source modules</strong><small><?= $data['source']['available'] ? $data['source']['api_modules'] . ' API modules · ' . $data['source']['cron_jobs'] . ' cron jobs' : 'Source unavailable' ?></small></span><i data-lucide="boxes"></i></div>
        </div></article>
    </div>
</section>
<?php dashboard_footer(); ?>