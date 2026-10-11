<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/discord_bots.php';

$settings = project_settings('discordbots');
$catalogue = discord_bots_features(isset($_GET['refresh']));
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$groups = [];
foreach ($catalogue['features'] as $feature) $groups[$feature['category']][] = $feature;
$orderedGroups = [];
foreach (['Applications', 'Access Control', 'Operations', 'Logs'] as $category) {
    if (isset($groups[$category])) $orderedGroups[$category] = $groups[$category];
}
foreach ($groups as $category => $features) {
    if (!isset($orderedGroups[$category])) $orderedGroups[$category] = $features;
}
$manageCount = count(array_filter($catalogue['features'], static fn(array $feature): bool => $feature['mode'] === 'Manage'));

dashboard_header($settings['title'] . ': Management', 'discord-bots-management', 'discordbots');
?>
<section class="view active">
    <div class="section-title project-title"><span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1>BOT MANAGEMENT</h1></span><div class="title-actions"><a class="secondary-button" href="<?= $escape(discord_bots_feature_url('/overview')) ?>" target="_blank" rel="noreferrer"><i data-lucide="external-link"></i>Open Professor</a><a class="primary-button" href="?refresh=1"><i data-lucide="refresh-cw"></i>Refresh</a></div></div>
    <?php if ($catalogue['stale']): ?><div class="data-notice"><i data-lucide="clock-alert"></i><div><strong>Showing the last successful feature catalogue</strong><p><?= $escape($catalogue['error']) ?></p></div></div><?php elseif ($catalogue['fallback']): ?><div class="data-notice"><i data-lucide="info"></i><div><strong>Showing the built-in feature catalogue</strong><p><?= $escape($catalogue['error']) ?></p></div></div><?php endif; ?>

    <div class="overview-metrics" aria-label="Professor feature summary">
        <article><span class="summary-icon"><i data-lucide="panels-top-left"></i></span><div><small>FEATURES</small><strong><?= number_format(count($catalogue['features'])) ?></strong><p>Professor workflows</p></div></article>
        <article><span class="summary-icon"><i data-lucide="pencil-ruler"></i></span><div><small>MANAGE</small><strong><?= number_format($manageCount) ?></strong><p>Interactive tools</p></div></article>
        <article><span class="summary-icon"><i data-lucide="eye"></i></span><div><small>VIEW</small><strong><?= number_format(count($catalogue['features']) - $manageCount) ?></strong><p>Read-only tools</p></div></article>
        <article><span class="summary-icon"><i data-lucide="folders"></i></span><div><small>GROUPS</small><strong><?= number_format(count($orderedGroups)) ?></strong><p>Management areas</p></div></article>
    </div>

    <div class="feature-directory"><?php foreach ($orderedGroups as $category => $features): ?><section class="feature-group"><div class="feature-group-heading"><span><small><?= count($features) ?> FEATURES</small><h2><?= $escape(strtoupper($category)) ?></h2></span><i data-lucide="<?= $category === 'Applications' ? 'files' : ($category === 'Access Control' ? 'shield-check' : ($category === 'Logs' ? 'scroll-text' : 'server-cog')) ?>"></i></div><div class="feature-list"><?php foreach ($features as $feature): ?><a class="feature-row" href="<?= $escape(discord_bots_feature_url($feature['path'])) ?>" target="_blank" rel="noreferrer"><span class="feature-icon"><i data-lucide="<?= $escape($feature['icon']) ?>"></i></span><span class="feature-copy"><strong><?= $escape($feature['name']) ?></strong><small><?= $escape($feature['description']) ?></small></span><span class="status-badge feature-mode-<?= strtolower($escape($feature['mode'])) ?>"><?= $escape($feature['mode']) ?></span><i data-lucide="external-link"></i></a><?php endforeach; ?></div></section><?php endforeach; ?></div>
</section>
<?php dashboard_footer(); ?>