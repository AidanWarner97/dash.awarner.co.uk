<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/project_settings.php';
require_once dirname(__DIR__, 2) . '/includes/evolution_bunny.php';
require_once dirname(__DIR__, 2) . '/includes/evolution_rate_limit.php';
require_once dirname(__DIR__, 2) . '/includes/evolution_health.php';

updates_start_session();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!updates_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('This form session has expired. Refresh the page and try again.');
        if (($_POST['action'] ?? '') === 'save_all') {
            project_settings_save('evolutioncdn', $_POST);
            evolution_bunny_save_settings($_POST);
            evolution_health_save_settings($_POST);
            evolution_rate_limit_save($_POST);
            header('Location: /evolution-cdn/settings/?saved=1');
        } elseif (($_POST['action'] ?? '') === 'save_bunny') {
            evolution_bunny_save_settings($_POST);
            header('Location: /evolution-cdn/settings/?bunny_saved=1#bunny-storage');
        } elseif (($_POST['action'] ?? '') === 'save_rate_limit') {
            evolution_rate_limit_save($_POST);
            header('Location: /evolution-cdn/settings/?rate_limit_saved=1#rate-limiter');
        } elseif (($_POST['action'] ?? '') === 'save_health') {
            evolution_health_save_settings($_POST);
            header('Location: /evolution-cdn/settings/?health_saved=1#health-monitoring');
        } else {
            project_settings_save('evolutioncdn', $_POST);
            header('Location: /evolution-cdn/settings/?saved=1');
        }
        exit;
    } catch (Throwable $exception) {
        $GLOBALS['project_settings_error'] = $exception->getMessage();
    }
}

$settings = project_settings('evolutioncdn');
dashboard_header($settings['title'] . ': Settings', 'evolution-settings', 'evolutioncdn');
render_project_settings('evolutioncdn', false);
$bunny = evolution_bunny_settings();
$rateLimit = evolution_rate_limit_settings();
$healthUrl = evolution_health_configured_endpoint();
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<section class="view active settings-extension-list">
    <?php if (isset($_GET['bunny_saved'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Bunny Storage settings saved.</div><?php endif; ?>
    <?php if (isset($_GET['health_saved'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Health monitor settings saved.</div><?php endif; ?>
    <?php if (isset($_GET['rate_limit_saved'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Rate limiter settings saved.</div><?php endif; ?>
    <input type="hidden" name="action" value="save_all" form="project-settings-form">
    <details class="settings-accordion" id="bunny-storage"<?= isset($_GET['bunny_saved']) ? ' open' : '' ?>><summary><span class="settings-accordion-icon"><i data-lucide="cloud"></i></span><span><strong>Bunny Storage</strong><small>Storage Zone access and delivery hostnames</small></span><span class="connection-state <?= evolution_bunny_configured() ? 'connected' : 'none' ?>"><i></i><?= evolution_bunny_configured() ? 'Configured' : 'Not configured' ?></span><i class="settings-chevron" data-lucide="chevron-down"></i></summary><div class="settings-accordion-body">
        <div class="settings-fields"><label>Storage Zone<input form="project-settings-form" name="storage_zone" required value="<?= $escape($bunny['zone']) ?>" placeholder="evolution-builds"></label><label>Region<input form="project-settings-form" name="storage_region" required value="<?= $escape($bunny['region']) ?>" placeholder="de"></label><label class="wide">Storage hostname<input form="project-settings-form" name="storage_host" required value="<?= $escape($bunny['host']) ?>" placeholder="storage.bunnycdn.com"><small>Use the regional Bunny hostname assigned to the Storage Zone.</small></label><label class="wide">CDN hostname<input form="project-settings-form" name="cdn_host" value="<?= $escape($bunny['cdn_host']) ?>" placeholder="downloads.evolution-x.org"></label><label class="wide">Storage access key<input form="project-settings-form" type="password" name="storage_access_key" autocomplete="new-password" placeholder="<?= $bunny['has_access_key'] ? 'Saved - leave blank to keep it' : 'Storage Zone password' ?>"></label></div>
        <div class="settings-actions"><a class="secondary-button" href="/evolution-cdn/files/"><i data-lucide="folder-open"></i>Browse bucket</a></div>
    </div></details>
    <details class="settings-accordion" id="health-monitoring"<?= isset($_GET['health_saved']) ? ' open' : '' ?>><summary><span class="settings-accordion-icon"><i data-lucide="heart-pulse"></i></span><span><strong>Health Monitoring</strong><small>Machine-readable origin endpoint and fallback behavior</small></span><span class="connection-state <?= $healthUrl !== '' ? 'connected' : 'none' ?>"><i></i><?= $healthUrl !== '' ? 'Internal endpoint' : 'Public fallback' ?></span><i class="settings-chevron" data-lucide="chevron-down"></i></summary><div class="settings-accordion-body">
        <div class="settings-fields"><p class="settings-help wide">Use an internal origin URL to avoid Cloudflare browser challenges. Leave blank to use the public API with automatic direct service checks when blocked.</p><label class="wide">Internal health endpoint<input form="project-settings-form" type="url" name="health_url" value="<?= $escape($healthUrl) ?>" placeholder="http://127.0.0.1/api/health"><small>The address must be reachable from the dashboard server and route to the Evolution X CDN application.</small></label></div>
        <div class="settings-actions"><a class="secondary-button" href="/evolution-cdn/health/"><i data-lucide="activity"></i>Open monitor</a></div>
    </div></details>
    <details class="settings-accordion" id="rate-limiter"<?= isset($_GET['rate_limit_saved']) ? ' open' : '' ?>><summary><span class="settings-accordion-icon"><i data-lucide="shield-check"></i></span><span><strong>Rate Limiter</strong><small>Download thresholds and escalating blocks</small></span><span class="connection-state connected"><i></i>Active</span><i class="settings-chevron" data-lucide="chevron-down"></i></summary><div class="settings-accordion-body">
        <div class="settings-fields rate-limit-fields">
            <p class="settings-help wide">Limits apply per user. IP blocking begins only when the distinct-user threshold is reached. A fourth offence remains a permanent block.</p>
            <label>Window (seconds)<input form="project-settings-form" type="number" name="window_seconds" min="1" max="3600" required value="<?= (int) $rateLimit['window_seconds'] ?>"></label>
            <label>Requests per window<input form="project-settings-form" type="number" name="max_requests" min="1" max="1000" required value="<?= (int) $rateLimit['max_requests'] ?>"></label>
            <label>Users per IP threshold<input form="project-settings-form" type="number" name="multi_user_ip_threshold" min="1" max="100" required value="<?= (int) $rateLimit['multi_user_ip_threshold'] ?>"></label>
            <span class="settings-spacer" aria-hidden="true"></span>
            <label>First block (minutes)<input form="project-settings-form" type="number" name="block_first_minutes" min="1" max="43200" required value="<?= (int) ($rateLimit['block_ladder'][0] / 60) ?>"></label>
            <label>Second block (minutes)<input form="project-settings-form" type="number" name="block_second_minutes" min="1" max="43200" required value="<?= (int) ($rateLimit['block_ladder'][1] / 60) ?>"></label>
            <label>Third block (minutes)<input form="project-settings-form" type="number" name="block_third_minutes" min="1" max="43200" required value="<?= (int) ($rateLimit['block_ladder'][2] / 60) ?>"></label>
        </div>
    </div></details>
    <div class="settings-page-actions"><button class="primary-button" type="submit" form="project-settings-form"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="save"></i>Save all settings</button></div>
</section>
<?php
dashboard_footer();