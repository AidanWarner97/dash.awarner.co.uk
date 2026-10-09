<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/evolution_bunny.php';

updates_start_session();
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$path = evolution_bunny_path((string) ($_GET['path'] ?? $_POST['path'] ?? ''));
$error = null;

if (isset($_GET['download'])) {
    try {
        evolution_bunny_download((string) $_GET['download']);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!updates_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('This form session has expired. Refresh the page and try again.');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'upload') evolution_bunny_upload($_FILES['file'] ?? [], $path);
        elseif ($action === 'create_directory') evolution_bunny_create_directory($path, (string) ($_POST['directory_name'] ?? ''));
        elseif ($action === 'delete') evolution_bunny_delete((string) ($_POST['object_path'] ?? ''));
        else throw new InvalidArgumentException('Select a valid bucket action.');
        header('Location: /evolution-cdn/files/?path=' . rawurlencode($path) . '&saved=' . rawurlencode($action));
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$items = [];
if (evolution_bunny_configured()) {
    try {
        $items = evolution_bunny_list($path);
    } catch (Throwable $exception) {
        $error ??= $exception->getMessage();
    }
} else {
    $error ??= 'Configure Bunny Storage in Evolution X CDN settings before browsing files.';
}

$breadcrumbs = [['name' => 'Bucket root', 'path' => '']];
$builtPath = '';
foreach ($path === '' ? [] : explode('/', $path) as $segment) {
    $builtPath = $builtPath === '' ? $segment : $builtPath . '/' . $segment;
    $breadcrumbs[] = ['name' => $segment, 'path' => $builtPath];
}
$parentPath = $path === '' ? null : dirname($path);
if ($parentPath === '.') $parentPath = '';
$settings = project_settings('evolutioncdn');
dashboard_header($settings['title'] . ': File Management', 'evolution-files', 'evolutioncdn');
?>
<section class="view active">
    <div class="section-title project-title"><span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1>FILE MANAGEMENT</h1></span><a class="secondary-button" href="/evolution-cdn/settings/#bunny-storage"><i data-lucide="settings"></i>Bucket settings</a></div>
    <?php if (isset($_GET['saved'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Bucket updated successfully.</div><?php endif; ?>
    <?php if ($error !== null): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($error) ?></div><?php endif; ?>
    <?php if (!updates_writes_enabled()): ?><div class="data-notice"><i data-lucide="lock-keyhole"></i><div><strong>Bucket changes are disabled</strong><p>File browsing remains available, but uploads and deletion require dashboard writes.</p></div></div><?php endif; ?>

    <div class="bucket-pathbar"><?php if ($parentPath !== null): ?><a class="secondary-button bucket-up" href="?path=<?= rawurlencode($parentPath) ?>"><i data-lucide="arrow-up"></i>Up</a><?php endif; ?><nav class="bucket-breadcrumbs" aria-label="Bucket path"><?php foreach ($breadcrumbs as $index => $crumb): ?><?php if ($index > 0): ?><i data-lucide="chevron-right"></i><?php endif; ?><a href="?path=<?= rawurlencode($crumb['path']) ?>"<?= $index === count($breadcrumbs) - 1 ? ' aria-current="page"' : '' ?>><?= $escape($crumb['name']) ?></a><?php endforeach; ?></nav></div>
    <div class="bucket-actions">
        <form class="bucket-action" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="path" value="<?= $escape($path) ?>"><label>Upload file<input type="file" name="file" required></label><button class="primary-button" type="submit"<?= updates_writes_enabled() && evolution_bunny_configured() ? '' : ' disabled' ?>><i data-lucide="upload"></i>Upload</button></form>
        <form class="bucket-action" method="post"><input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="action" value="create_directory"><input type="hidden" name="path" value="<?= $escape($path) ?>"><label>New folder<input name="directory_name" maxlength="255" required placeholder="Folder name"></label><button class="secondary-button" type="submit"<?= updates_writes_enabled() && evolution_bunny_configured() ? '' : ' disabled' ?>><i data-lucide="folder-plus"></i>Create</button></form>
    </div>
    <article class="content-card bucket-browser"><div class="card-heading"><div><small><?= count($items) ?> ITEMS</small><h2><?= $path === '' ? 'BUCKET ROOT' : $escape(basename($path)) ?></h2></div><i data-lucide="cloud"></i></div>
        <?php if (!$items && $error === null): ?><div class="list-message">This folder is empty.</div><?php endif; ?>
        <div class="bucket-list"><?php foreach ($items as $item): ?><div class="bucket-row"><span class="bucket-file-icon"><i data-lucide="<?= $item['is_directory'] ? 'folder' : 'file' ?>"></i></span><span class="bucket-name"><?php if ($item['is_directory']): ?><a href="?path=<?= rawurlencode($item['path']) ?>"><?= $escape($item['name']) ?></a><?php else: ?><strong><?= $escape($item['name']) ?></strong><?php endif; ?><small><?= $item['modified_at'] !== '' ? $escape(date('j M Y, H:i', strtotime($item['modified_at']))) : 'Unknown modification time' ?></small></span><span class="bucket-size"><?= $item['is_directory'] ? 'Folder' : $escape(evolution_bunny_size($item['size'])) ?></span><div class="table-actions"><?php if (!$item['is_directory']): ?><a class="icon-button" href="?path=<?= rawurlencode($path) ?>&download=<?= rawurlencode($item['path']) ?>" title="Download" aria-label="Download <?= $escape($item['name']) ?>"><i data-lucide="download"></i></a><?php endif; ?><form method="post" onsubmit="return confirm('Delete <?= $escape($item['name']) ?> from Bunny Storage?')"><input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="path" value="<?= $escape($path) ?>"><input type="hidden" name="object_path" value="<?= $escape($item['path']) ?>"><button class="icon-button delete-update" type="submit" title="Delete" aria-label="Delete <?= $escape($item['name']) ?>"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="trash-2"></i></button></form></div></div><?php endforeach; ?></div>
    </article>
</section>
<?php dashboard_footer(); ?>