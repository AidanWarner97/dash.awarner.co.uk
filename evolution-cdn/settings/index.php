<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/project_settings.php';
require_once dirname(__DIR__, 2) . '/includes/evolution_bunny.php';
require_once dirname(__DIR__, 2) . '/includes/evolution_rate_limit.php';

updates_start_session();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!updates_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('This form session has expired. Refresh the page and try again.');
        if (($_POST['action'] ?? '') === 'save_bunny') {
            evolution_bunny_save_settings($_POST);
            header('Location: /evolution-cdn/settings/?bunny_saved=1#bunny-storage');
        } elseif (($_POST['action'] ?? '') === 'save_rate_limit') {
            evolution_rate_limit_save($_POST);
            header('Location: /evolution-cdn/settings/?rate_limit_saved=1#rate-limiter');
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
render_project_settings('evolutioncdn');
$bunny = evolution_bunny_settings();
$rateLimit = evolution_rate_limit_settings();
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<section class="view active" id="bunny-storage">
    <?php if (isset($_GET['bunny_saved'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Bunny Storage settings saved.</div><?php endif; ?>
    <form class="content-card settings-card bunny-settings-card" method="post">
        <input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="action" value="save_bunny">
        <div class="card-heading"><div><small>FILE STORAGE</small><h2>BUNNY STORAGE API</h2></div><span class="connection-state <?= evolution_bunny_configured() ? 'connected' : 'none' ?>"><i></i><?= evolution_bunny_configured() ? 'Configured' : 'Not configured' ?></span></div>
        <div class="settings-fields"><label>Storage Zone<input name="storage_zone" required value="<?= $escape($bunny['zone']) ?>" placeholder="evolution-builds"></label><label>Region<input name="storage_region" required value="<?= $escape($bunny['region']) ?>" placeholder="de"></label><label class="wide">Storage hostname<input name="storage_host" required value="<?= $escape($bunny['host']) ?>" placeholder="storage.bunnycdn.com"><small>Use the regional Bunny hostname assigned to the Storage Zone.</small></label><label class="wide">CDN hostname<input name="cdn_host" value="<?= $escape($bunny['cdn_host']) ?>" placeholder="downloads.evolution-x.org"></label><label class="wide">Storage access key<input type="password" name="storage_access_key" autocomplete="new-password" placeholder="<?= $bunny['has_access_key'] ? 'Saved - leave blank to keep it' : 'Storage Zone password' ?>"></label></div>
        <div class="settings-actions"><a class="secondary-button" href="/evolution-cdn/files/"><i data-lucide="folder-open"></i>Browse bucket</a><button class="primary-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="save"></i>Save Bunny settings</button></div>
    </form>
</section>
<section class="view active" id="rate-limiter">
    <?php if (isset($_GET['rate_limit_saved'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Rate limiter settings saved.</div><?php endif; ?>
    <form class="content-card settings-card rate-limit-settings-card" method="post">
        <input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="action" value="save_rate_limit">
        <div class="card-heading"><div><small>DOWNLOAD PROTECTION</small><h2>RATE LIMITER</h2></div><span class="connection-state connected"><i></i>Active</span></div>
        <div class="settings-fields rate-limit-fields">
            <p class="settings-help wide">Limits apply per user. IP blocking begins only when the distinct-user threshold is reached. A fourth offence remains a permanent block.</p>
            <label>Window (seconds)<input type="number" name="window_seconds" min="1" max="3600" required value="<?= (int) $rateLimit['window_seconds'] ?>"></label>
            <label>Requests per window<input type="number" name="max_requests" min="1" max="1000" required value="<?= (int) $rateLimit['max_requests'] ?>"></label>
            <label>Users per IP threshold<input type="number" name="multi_user_ip_threshold" min="1" max="100" required value="<?= (int) $rateLimit['multi_user_ip_threshold'] ?>"></label>
            <span class="settings-spacer" aria-hidden="true"></span>
            <label>First block (minutes)<input type="number" name="block_first_minutes" min="1" max="43200" required value="<?= (int) ($rateLimit['block_ladder'][0] / 60) ?>"></label>
            <label>Second block (minutes)<input type="number" name="block_second_minutes" min="1" max="43200" required value="<?= (int) ($rateLimit['block_ladder'][1] / 60) ?>"></label>
            <label>Third block (minutes)<input type="number" name="block_third_minutes" min="1" max="43200" required value="<?= (int) ($rateLimit['block_ladder'][2] / 60) ?>"></label>
        </div>
        <div class="settings-actions"><button class="primary-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="save"></i>Save rate limits</button></div>
    </form>
</section>
<?php
dashboard_footer();