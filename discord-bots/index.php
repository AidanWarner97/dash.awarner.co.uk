<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/discord_bots.php';

$settings = project_settings('discordbots');
$data = discord_bots_overview(isset($_GET['refresh']));
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$statusLabels = ['healthy' => 'Online', 'warning' => 'Unknown', 'error' => 'Offline'];

dashboard_header($settings['title'] . ': Overview', 'discord-bots-overview', 'discordbots');
?>
<section class="view active">
    <div class="section-title project-title">
        <span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1>DISCORD BOTS</h1></span>
        <div class="title-actions"><a class="secondary-button" href="/discord-bots/management/"><i data-lucide="panels-top-left"></i>Management</a><a class="primary-button" href="?refresh=1"><i data-lucide="refresh-cw"></i>Refresh</a></div>
    </div>
    <?php if ($data['stale']): ?><div class="data-notice"><i data-lucide="clock-alert"></i><div><strong>Showing the last successful response</strong><p><?= $escape($data['error']) ?></p></div></div><?php elseif ($data['error'] !== null): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($data['error']) ?> <a href="/discord-bots/settings/">Open settings</a></div><?php endif; ?>

    <div class="overview-metrics" aria-label="Discord bot summary">
        <article><span class="summary-icon"><i data-lucide="users"></i></span><div><small>USERS</small><strong><?= number_format($data['stats']['total_users']) ?></strong><p>Known Discord users</p></div></article>
        <article><span class="summary-icon"><i data-lucide="clipboard-list"></i></span><div><small>APPLICATIONS</small><strong><?= number_format($data['stats']['total_applications']) ?></strong><p><?= number_format($data['stats']['pending_applications']) ?> pending review</p></div></article>
        <article><span class="summary-icon"><i data-lucide="shield-alert"></i></span><div><small>OPEN ABUSE</small><strong><?= number_format($data['stats']['open_abuse_records']) ?></strong><p>Unresolved records</p></div></article>
        <article><span class="summary-icon"><i data-lucide="server"></i></span><div><small>SERVICES</small><strong><?= number_format(count($data['services'])) ?></strong><p><?= $escape($statusLabels[$data['status']] ?? 'Unknown') ?> overall</p></div></article>
    </div>

    <article class="content-card overview-panel"><div class="card-heading"><div><small>EVOLUTION X</small><h2>SERVICE STATUS</h2></div><a class="text-button" href="/discord-bots/health/">View details <i data-lucide="arrow-right"></i></a></div><div class="overview-list">
        <?php if (!$data['services']): ?><div class="list-message">No service telemetry is available.</div><?php endif; ?>
        <?php foreach ($data['services'] as $service): ?><div class="overview-row"><span><strong><?= $escape($service['name']) ?></strong><small><?= $escape($service['message']) ?></small></span><span class="status-badge health-status-<?= $escape($service['status']) ?>"><?= $escape($statusLabels[$service['status']] ?? 'Unknown') ?></span></div><?php endforeach; ?>
    </div></article>
</section>
<?php dashboard_footer(); ?>