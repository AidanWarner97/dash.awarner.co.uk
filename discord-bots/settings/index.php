<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/discord_bots.php';

updates_start_session();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!updates_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('This form session has expired. Refresh the page and try again.');
        project_settings_save('discordbots', $_POST);
        discord_bots_save_settings($_POST);
        header('Location: /discord-bots/settings/?saved=1');
        exit;
    } catch (Throwable $exception) {
        $GLOBALS['project_settings_error'] = $exception->getMessage();
    }
}

$settings = project_settings('discordbots');
$remote = discord_bots_remote_settings();
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
dashboard_header($settings['title'] . ': Settings', 'discord-bots-settings', 'discordbots');
render_project_settings('discordbots', false);
?>
<section class="view active settings-extension-list">
    <details class="settings-accordion" id="remote-management"<?= !empty($GLOBALS['project_settings_error']) ? ' open' : '' ?>><summary><span class="settings-accordion-icon"><i data-lucide="key-round"></i></span><span><strong>Remote Management</strong><small>Signed access to Professor and the shared bot database</small></span><span class="connection-state <?= discord_bots_remote_configured() ? 'connected' : 'none' ?>"><i></i><?= discord_bots_remote_configured() ? 'Configured' : 'Not configured' ?></span><i class="settings-chevron" data-lucide="chevron-down"></i></summary><div class="settings-accordion-body">
        <div class="settings-fields">
            <p class="settings-help wide">Professor keeps MariaDB private and exposes only allowlisted management operations. Requests use a short-lived signature with replay protection; the signing key is derived automatically each UTC day.</p>
            <label class="wide">Professor API URL<input form="project-settings-form" type="url" name="api_url" value="<?= $escape($remote['url']) ?>" placeholder="https://professor.example.com"><small>Base URL reachable from this dashboard. HTTPS is required across public networks.</small></label>
            <label class="wide">API identifier<input form="project-settings-form" name="api_identifier" value="<?= $escape($remote['identifier']) ?>" placeholder="awarner-dashboard" autocomplete="username"></label>
            <label class="wide">Shared secret<input form="project-settings-form" type="password" name="api_secret" autocomplete="new-password" placeholder="<?= $remote['has_secret'] ? 'Saved - leave blank to keep it' : 'At least 32 characters' ?>"><small>Set the same values as <code>DASHBOARD_EXTERNAL_IDENTIFIER</code> and <code>DASHBOARD_EXTERNAL_PASSWORD</code> in Professor.</small></label>
        </div>
        <div class="settings-actions"><a class="secondary-button" href="/discord-bots/health/"><i data-lucide="activity"></i>Open monitor</a></div>
    </div></details>
    <div class="settings-page-actions"><button class="primary-button" type="submit" form="project-settings-form"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="save"></i>Save all settings</button></div>
</section>
<?php dashboard_footer(); ?>