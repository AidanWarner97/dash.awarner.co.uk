<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/evolution_access_logs.php';

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$error = null;
$data = ['rows' => [], 'filters' => evolution_access_log_filters($_GET), 'routes' => [], 'total' => 0, 'pages' => 1, 'per_page' => 50, 'summary' => ['all_time' => 0, 'today' => 0, 'limited' => 0]];
try {
    $data = evolution_access_logs($_GET);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
$pageUrl = static function (int $page) use ($data): string {
    $query = array_filter($data['filters'], static fn(mixed $value): bool => $value !== '' && $value !== 'all');
    $query['page'] = $page;
    return '?' . http_build_query($query);
};
$settings = project_settings('evolutioncdn');
dashboard_header($settings['title'] . ': Access Logs', 'evolution-access', 'evolutioncdn');
?>
<section class="view active">
    <div class="section-title project-title"><span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1>ACCESS LOGS</h1></span><a class="secondary-button" href="/evolution-cdn/database/?table=requests"><i data-lucide="database"></i>View table</a></div>
    <?php if ($error !== null): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($error) ?></div><?php endif; ?>
    <div class="log-summary access-summary"><span><small>TODAY</small><strong><?= number_format($data['summary']['today']) ?></strong></span><span><small>RATE LIMITED TODAY</small><strong><?= number_format($data['summary']['limited']) ?></strong></span><span><small>ALL TIME</small><strong><?= number_format($data['summary']['all_time']) ?></strong></span><span><small>FILTERED</small><strong><?= number_format($data['total']) ?></strong></span></div>
    <form class="access-filters" method="get">
        <label class="search-field"><i data-lucide="search"></i><input type="search" name="q" value="<?= $escape($data['filters']['q']) ?>" placeholder="Search IP, user, route, path, or agent"></label>
        <label><span class="visually-hidden">Route</span><select name="route"><option value="">All routes</option><?php foreach ($data['routes'] as $route): ?><option value="<?= $escape($route) ?>"<?= $data['filters']['route'] === $route ? ' selected' : '' ?>><?= $escape($route) ?></option><?php endforeach; ?></select></label>
        <label><span class="visually-hidden">Rate limit state</span><select name="limited"><option value="all">All access</option><option value="yes"<?= $data['filters']['limited'] === 'yes' ? ' selected' : '' ?>>Rate limited</option><option value="no"<?= $data['filters']['limited'] === 'no' ? ' selected' : '' ?>>Not limited</option></select></label>
        <label>From<input type="date" name="from" value="<?= $escape($data['filters']['from']) ?>"></label><label>To<input type="date" name="to" value="<?= $escape($data['filters']['to']) ?>"></label>
        <button class="primary-button" type="submit">Filter</button><a class="secondary-button" href="/evolution-cdn/access-logs/">Reset</a>
    </form>
    <article class="content-card access-log-card"><div class="card-heading"><div><small>PAGE <?= $data['filters']['page'] ?> OF <?= $data['pages'] ?></small><h2>REQUEST ACTIVITY</h2></div><span class="database-count"><?= number_format($data['total']) ?> records</span></div>
        <?php if (!$data['rows'] && $error === null): ?><div class="list-message">No access records match these filters.</div><?php endif; ?>
        <div class="access-table-wrap"><table class="access-table"><thead><tr><th>Time</th><th>Identity</th><th>Route and path</th><th>User agent</th><th>Status</th></tr></thead><tbody><?php foreach ($data['rows'] as $row): ?><tr><td><time><?= $escape(date('j M Y', strtotime((string) $row['created_at']))) ?><small><?= $escape(date('H:i:s', strtotime((string) $row['created_at']))) ?></small></time></td><td><strong><?= $escape($row['ip_address']) ?></strong><small><?= $escape($row['user_id'] ?: 'Anonymous') ?></small></td><td><strong><?= $escape($row['route_name']) ?></strong><small title="<?= $escape($row['file_path']) ?>"><?= $escape(mb_strimwidth((string) $row['file_path'], 0, 72, '...')) ?></small></td><td><span title="<?= $escape($row['user_agent']) ?>"><?= $escape(mb_strimwidth((string) $row['user_agent'], 0, 56, '...')) ?></span></td><td><?php if ($row['rate_limited']): ?><span class="status-badge status-failed" title="<?= $escape($row['rate_limit_action']) ?>">Limited</span><?php else: ?><span class="status-badge status-completed">Allowed</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php if ($data['pages'] > 1): ?><nav class="pagination" aria-label="Access log pages"><?php if ($data['filters']['page'] > 1): ?><a class="icon-button" href="<?= $escape($pageUrl($data['filters']['page'] - 1)) ?>" aria-label="Previous page"><i data-lucide="chevron-left"></i></a><?php endif; ?><?php $first = max(1, $data['filters']['page'] - 2); $last = min($data['pages'], $data['filters']['page'] + 2); for ($page = $first; $page <= $last; $page++): ?><a href="<?= $escape($pageUrl($page)) ?>"<?= $page === $data['filters']['page'] ? ' aria-current="page"' : '' ?>><?= $page ?></a><?php endfor; ?><?php if ($data['filters']['page'] < $data['pages']): ?><a class="icon-button" href="<?= $escape($pageUrl($data['filters']['page'] + 1)) ?>" aria-label="Next page"><i data-lucide="chevron-right"></i></a><?php endif; ?></nav><?php endif; ?>
    </article>
</section>
<?php dashboard_footer(); ?>