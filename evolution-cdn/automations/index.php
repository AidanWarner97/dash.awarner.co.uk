<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/evolution_automations.php';

updates_start_session();
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$error = null;
$database = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!updates_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('This form session has expired. Refresh the page and try again.');
        $database = evolution_automations_database();
        $key = (string) ($_POST['automation_key'] ?? '');
        if (($_POST['action'] ?? '') === 'save') {
            evolution_automation_save($database, $key, isset($_POST['enabled']), (string) ($_POST['schedule'] ?? ''));
            header('Location: /evolution-cdn/automations/?saved=1');
        } elseif (($_POST['action'] ?? '') === 'toggle') {
            evolution_automation_set_enabled($database, $key, (string) ($_POST['enabled'] ?? '0') === '1');
            header('Location: /evolution-cdn/automations/?toggled=1');
        } elseif (($_POST['action'] ?? '') === 'run') {
            evolution_automation_request_run($database, $key);
            header('Location: /evolution-cdn/automations/?queued=1');
        } else {
            throw new InvalidArgumentException('Select a valid automation action.');
        }
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$automations = [];
$runs = [];
try {
    $database ??= evolution_automations_database();
    $automations = evolution_automations_list($database);
    $runs = evolution_automations_recent_runs($database);
} catch (Throwable $exception) {
    $error ??= 'Automations are unavailable: ' . $exception->getMessage();
}

$settings = project_settings('evolutioncdn');
$definitions = evolution_automation_definitions();
$frequencies = evolution_automation_frequencies();
dashboard_header($settings['title'] . ': Automations', 'evolution-automations', 'evolutioncdn');
?>
<section class="view active">
    <div class="section-title project-title"><span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1>AUTOMATIONS</h1></span><p>One minute runner evaluates every enabled schedule.</p></div>
    <?php if (isset($_GET['saved'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Automation schedule saved.</div><?php endif; ?>
    <?php if (isset($_GET['toggled'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Automation state updated.</div><?php endif; ?>
    <?php if (isset($_GET['queued'])): ?><div class="flash success"><i data-lucide="timer"></i>Automation queued for the next runner cycle.</div><?php endif; ?>
    <?php if ($error !== null): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($error) ?></div><?php endif; ?>
    <?php if (!updates_writes_enabled()): ?><div class="data-notice"><i data-lucide="lock-keyhole"></i><div><strong>Automation changes are disabled</strong><p>Enable dashboard writes before changing schedules or requesting runs.</p></div></div><?php endif; ?>

    <article class="content-card automation-table-card">
        <div class="card-heading"><div><small><?= count($automations) ?> SCHEDULED TASKS</small><h2>WORKER SCHEDULE</h2></div><span class="database-count">cron.php every minute</span></div>
        <div class="automation-table"><?php foreach ($automations as $automation): ?><div class="automation-row">
            <span class="automation-icon"><i data-lucide="<?= !empty($automation['enabled']) ? 'timer' : 'pause' ?>"></i></span>
            <span class="automation-details"><span class="automation-name"><strong><?= $escape($automation['name']) ?></strong><span class="status-badge <?= !empty($automation['enabled']) ? 'status-completed' : '' ?>"><?= !empty($automation['enabled']) ? 'Enabled' : 'Paused' ?></span></span><small><?= $escape($automation['description']) ?></small><small>Last run: <?= $automation['last_started_at'] ? $escape(date('j M Y, H:i', strtotime((string) $automation['last_started_at']))) . ' · ' . $escape($automation['last_status'] ?? 'unknown') : 'Never' ?><?= $automation['next_run_at'] ? ' · Next: ' . $escape(date('j M Y, H:i', strtotime($automation['next_run_at']))) : '' ?></small></span>
            <form method="post" class="automation-schedule"><input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="automation_key" value="<?= $escape($automation['key']) ?>"><?php if (!empty($automation['enabled'])): ?><input type="hidden" name="enabled" value="1"><?php endif; ?><label>Schedule<select name="schedule"><?php if (!isset($frequencies[$automation['schedule']])): ?><option value="<?= $escape($automation['schedule']) ?>" selected>Existing custom schedule</option><?php endif; ?><?php foreach ($frequencies as $expression => $label): ?><option value="<?= $escape($expression) ?>"<?= $automation['schedule'] === $expression ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label><button class="icon-button" type="submit" title="Save schedule" aria-label="Save <?= $escape($automation['name']) ?> schedule"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="save"></i></button></form>
            <div class="automation-row-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="automation_key" value="<?= $escape($automation['key']) ?>"><input type="hidden" name="enabled" value="<?= !empty($automation['enabled']) ? '0' : '1' ?>"><button class="secondary-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>><?= !empty($automation['enabled']) ? 'Pause' : 'Enable' ?></button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="action" value="run"><input type="hidden" name="automation_key" value="<?= $escape($automation['key']) ?>"><button class="secondary-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="play"></i>Run now</button></form></div>
        </div><?php endforeach; ?></div>
    </article>

    <article class="content-card automation-history"><div class="card-heading"><div><small>LAST <?= count($runs) ?></small><h2>RUN HISTORY</h2></div><i data-lucide="history"></i></div>
        <?php if (!$runs): ?><div class="list-message">No automation runs have been recorded.</div><?php endif; ?>
        <div class="automation-run-list"><?php foreach ($runs as $run): ?><?php $definition = $definitions[$run['automation_key']] ?? ['name' => $run['automation_key']]; ?><details class="automation-run"><summary><span><strong><?= $escape($definition['name']) ?></strong><small><?= $escape($run['trigger_type']) ?> · <?= $escape(date('j M Y, H:i:s', strtotime((string) $run['started_at']))) ?><?= $run['duration_ms'] !== null ? ' · ' . number_format((int) $run['duration_ms']) . ' ms' : '' ?></small></span><span class="status-badge status-<?= $escape($run['status']) ?>"><?= $escape($run['status']) ?></span><i data-lucide="chevron-down"></i></summary><?php if ($run['error_message']): ?><p class="log-error"><?= $escape($run['error_message']) ?></p><?php endif; ?><pre><?= $escape($run['output'] ?: 'No output captured.') ?></pre></details><?php endforeach; ?></div>
    </article>
</section>
<?php dashboard_footer(); ?>