<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/evolution_health.php';

$settings = project_settings('evolutioncdn');
$health = evolution_health_monitor(isset($_GET['refresh']));
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$statusLabels = ['healthy' => 'Operational', 'warning' => 'Degraded', 'error' => 'Unavailable', 'building' => 'Busy', 'idle' => 'Idle'];
$counts = array_fill_keys(['healthy', 'warning', 'error', 'building', 'idle'], 0);
foreach ($health['services'] as $service) $counts[$service['status']]++;

dashboard_header($settings['title'] . ': Health Monitor', 'evolution-health', 'evolutioncdn');
?>
<section class="view active">
    <div class="section-title project-title"><span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1>HEALTH MONITOR</h1></span><div class="title-actions"><a class="secondary-button" href="https://<?= $escape($settings['domain']) ?>/health" target="_blank" rel="noreferrer"><i data-lucide="external-link"></i>Public health</a><a class="primary-button" href="?refresh=1"><i data-lucide="refresh-cw"></i>Refresh</a></div></div>
    <?php if ($health['stale']): ?><div class="data-notice"><i data-lucide="clock-alert"></i><div><strong>Showing the last successful check</strong><p><?= $escape($health['error']) ?></p></div></div><?php elseif ($health['error'] !== null): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($health['error']) ?></div><?php endif; ?>
    <?php if (!empty($health['notice'])): ?><div class="data-notice"><i data-lucide="shield-check"></i><div><strong>Direct service checks active</strong><p><?= $escape($health['notice']) ?></p></div></div><?php endif; ?>

    <article class="health-overall health-<?= $escape($health['status']) ?>"><span class="health-overall-icon"><i data-lucide="<?= $health['status'] === 'healthy' ? 'circle-check' : ($health['status'] === 'error' ? 'circle-x' : 'triangle-alert') ?>"></i></span><span><small>OVERALL STATUS</small><h2><?= $escape($statusLabels[$health['status']] ?? 'Degraded') ?></h2><p>Checked <?= $escape(date('j M Y, H:i:s', strtotime($health['checked_at']))) ?><?= $health['response_ms'] !== null ? ' · API response ' . number_format((int) $health['response_ms']) . ' ms' : '' ?></p></span></article>

    <div class="health-summary" aria-label="Service health summary"><span><small>OPERATIONAL</small><strong><?= number_format($counts['healthy']) ?></strong></span><span><small>DEGRADED</small><strong><?= number_format($counts['warning']) ?></strong></span><span><small>UNAVAILABLE</small><strong><?= number_format($counts['error']) ?></strong></span><span><small>ACTIVE / IDLE</small><strong><?= number_format($counts['building'] + $counts['idle']) ?></strong></span></div>

    <article class="content-card health-services"><div class="card-heading"><div><small><?= count($health['services']) ?> SERVICES</small><h2>COMPONENT STATUS</h2></div><i data-lucide="heart-pulse"></i></div>
        <?php if (!$health['services']): ?><div class="list-message">No component health data is available.</div><?php endif; ?>
        <div class="health-service-list"><?php foreach ($health['services'] as $service): ?><details class="health-service"><summary><span class="health-service-icon health-<?= $escape($service['status']) ?>"><i data-lucide="<?= $service['status'] === 'healthy' ? 'check' : ($service['status'] === 'error' ? 'x' : ($service['status'] === 'building' ? 'activity' : 'minus')) ?>"></i></span><span><strong><?= $escape($service['name']) ?></strong><small><?= $escape($service['message']) ?></small></span><span class="status-badge health-status-<?= $escape($service['status']) ?>"><?= $escape($statusLabels[$service['status']] ?? ucfirst($service['status'])) ?></span><i data-lucide="chevron-down"></i></summary><?php if ($service['details']): ?><div class="health-details"><?php foreach ($service['details'] as $label => $value): ?><span><small><?= $escape($label) ?></small><strong title="<?= $escape($value) ?>"><?= $escape($value) ?></strong></span><?php endforeach; ?></div><?php else: ?><p class="health-no-details">No additional telemetry supplied.</p><?php endif; ?></details><?php endforeach; ?></div>
    </article>
</section>
<?php dashboard_footer(); ?>
