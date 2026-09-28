<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/catalogue_manager.php';

updates_start_session();
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$error = null;
$settings = project_settings('tileimagegen');
$imageUrl = static function (string $path) use ($settings): string {
    if (bunny_storage_enabled()) {
        return bunny_storage_url($path);
    }
    return 'https://' . $settings['domain'] . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
};
$ajaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if (isset($_GET['csv-template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="catalogue-import-template.csv"');
    echo "brand,range,version,size,width,height\r\n";
    echo "Easy Bathrooms,Charlie,Blue,1200 x 600,1200,600\r\n";
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!updates_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('This form session has expired. Refresh the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? 'save');
        if ($action === 'delete-image') {
            catalogue_delete_image((string) ($_POST['image'] ?? ''));
            header('Location: /tileimagegen/catalogue/?deleted=1');
            exit;
        }
        if ($action === 'delete-size') {
            $result = catalogue_delete_size($_POST);
            if ($ajaxRequest) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['ok' => true, 'summary' => $result['summary']], JSON_THROW_ON_ERROR);
                exit;
            }
            header('Location: /tileimagegen/catalogue/?deleted-size=1');
            exit;
        }
        if ($action === 'import-csv') {
            $result = catalogue_import_csv_upload($_FILES['catalogue_csv'] ?? []);
            header('Location: /tileimagegen/catalogue/?imported=1&rows=' . $result['rows'] . '&created=' . $result['created'] . '&updated=' . $result['updated']);
            exit;
        }

        $result = catalogue_upsert_size($_POST, $_FILES['images'] ?? []);
        if ($ajaxRequest) {
            $entry = $result['entry'];
            $entry['images'] = array_map(static fn(string $path): array => ['name' => basename($path), 'url' => $imageUrl($path)], $entry['images']);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok' => true,
                'uploaded' => $result['uploaded'],
                'entry' => $entry,
                'total_images' => catalogue_summary($result['catalogue'])['images'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            exit;
        }
        header('Location: /tileimagegen/catalogue/?saved=1&uploaded=' . $result['uploaded']);
        exit;
    } catch (Throwable $exception) {
        if ($ajaxRequest) {
            http_response_code($exception instanceof InvalidArgumentException ? 422 : 500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
            exit;
        }
        $error = $exception->getMessage();
    }
}

$summary = ['brands' => 0, 'ranges' => 0, 'versions' => 0, 'sizes' => 0, 'images' => 0];

dashboard_header($settings['title'] . ': Catalogue', 'tile-catalogue', 'tileimagegen');
?>
<section class="view active">
    <div class="section-title project-title">
        <span><small><?= $escape(strtoupper($settings['domain'])) ?></small><h1>IMAGE CATALOGUE</h1></span>
        <div class="title-actions"><button class="primary-button" type="button" data-catalogue-import-open><i data-lucide="file-up"></i>Bulk import</button><a class="secondary-button" href="https://<?= $escape($settings['domain']) ?>" target="_blank" rel="noreferrer"><i data-lucide="external-link"></i>Open generator</a></div>
    </div>
    <?php if (isset($_GET['saved'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Catalogue entry saved<?= ((int) ($_GET['uploaded'] ?? 0)) > 0 ? ' with ' . (int) $_GET['uploaded'] . ' new image(s)' : '' ?>.</div><?php endif; ?>
    <?php if (isset($_GET['imported'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Imported <?= (int) ($_GET['rows'] ?? 0) ?> catalogue row(s): <?= (int) ($_GET['created'] ?? 0) ?> created and <?= (int) ($_GET['updated'] ?? 0) ?> updated.</div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Catalogue image deleted.</div><?php endif; ?>
    <?php if (isset($_GET['deleted-size'])): ?><div class="flash success"><i data-lucide="circle-check"></i>Catalogue entry deleted.</div><?php endif; ?>
    <?php if ($error !== null): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= $escape($error) ?></div><?php endif; ?>
    <?php if (!updates_writes_enabled()): ?><div class="data-notice"><i data-lucide="lock-keyhole"></i><div><strong>Catalogue writing is disabled</strong><p>Enable authenticated dashboard writes before changing catalogue data.</p></div></div><?php endif; ?>

    <section class="catalogue-storage-status" data-catalogue-storage-status data-endpoint="/api/tileimagegen/catalogue.php">
        <div class="catalogue-storage-heading"><div><small>CDN MIGRATION</small><strong>Bunny Storage</strong></div><span data-storage-state>Checking</span></div>
        <div class="catalogue-storage-progress" role="progressbar" aria-label="Bunny Storage migration" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i data-storage-progress></i></div>
        <div class="catalogue-storage-metrics">
            <span><small>VERIFIED</small><strong data-storage-ready>—</strong></span>
            <span><small>PENDING</small><strong data-storage-pending>—</strong></span>
            <span><small>ERRORS</small><strong data-storage-errors>—</strong></span>
            <span><small>UPLOADED</small><strong data-storage-bytes>—</strong></span>
        </div>
        <p data-storage-updated>Loading migration status…</p>
    </section>

    <div class="catalogue-summary" aria-label="Catalogue totals">
        <?php foreach ($summary as $label => $total): ?><span><small><?= $escape(strtoupper($label)) ?></small><strong data-catalogue-total="<?= $escape($label) ?>"><?= (int) $total ?></strong></span><?php endforeach; ?>
    </div>

    <div class="catalogue-layout">
        <form class="content-card catalogue-editor" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <div class="card-heading"><div><small>NEW PREDEFINED TILE</small><h2>ADD CATALOGUE ENTRY</h2></div><i data-lucide="image-plus"></i></div>
            <div class="settings-fields catalogue-add-fields">
                <label>Brand<input name="brand" list="catalogue-brands" maxlength="100" required placeholder="Easy Bathrooms"></label>
                <label>Range<input name="range" maxlength="100" required placeholder="Charlie"></label>
                <label>Version<input name="version" maxlength="100" required placeholder="Blue"></label>
                <label>Size label<input name="size" maxlength="100" required placeholder="1200 x 600"></label>
                <label>Width (px)<input type="number" name="width" min="1" max="10000" required></label>
                <label>Height (px)<input type="number" name="height" min="1" max="10000" required></label>
                <label class="wide">Tile images<input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple><small>JPEG, PNG, or WebP. Up to 12 images, 15 MB each. Leave empty to update an existing size.</small></label>
            </div>
            <div class="catalogue-actions"><button class="primary-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="save"></i>Save entry</button></div>
        </form>
        <datalist id="catalogue-brands" data-catalogue-brands></datalist>

        <div class="content-card catalogue-manager" data-catalogue-manager data-endpoint="/api/tileimagegen/catalogue.php" data-csrf-token="<?= $escape(updates_csrf_token()) ?>" data-writes-enabled="<?= updates_writes_enabled() ? '1' : '0' ?>">
            <div class="card-heading"><div><small data-catalogue-manager-count>LOADING</small><h2>PREDEFINED IMAGES</h2></div><span class="catalogue-refresh-state" data-catalogue-refresh-state>Loading…</span></div>
            <div class="list-message" data-catalogue-list>Loading catalogue…</div>
        </div>
    </div>
    <dialog class="catalogue-modal" data-catalogue-modal>
        <form method="post" enctype="multipart/form-data" data-catalogue-edit-form>
            <input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <?php foreach (['brand', 'range', 'version', 'size'] as $field): ?><input type="hidden" name="original_<?= $field ?>"><?php endforeach; ?>
            <div class="catalogue-modal-heading"><div><small>EDIT PREDEFINED TILE</small><h2>CATALOGUE ENTRY</h2></div><button class="icon-button" type="button" data-catalogue-modal-close aria-label="Close edit dialog"><i data-lucide="x"></i></button></div>
            <div class="settings-fields catalogue-edit-fields">
                <label>Brand<input name="brand" maxlength="100" required></label>
                <label>Range<input name="range" maxlength="100" required></label>
                <label>Version<input name="version" maxlength="100" required></label>
                <label>Size label<input name="size" maxlength="100" required></label>
                <label>Width (px)<input type="number" name="width" min="1" max="10000" required></label>
                <label>Height (px)<input type="number" name="height" min="1" max="10000" required></label>
                <div class="catalogue-edit-images" data-catalogue-edit-images></div>
                <label>Upload additional images<input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple><small>New images are added to the entry unless replacement is selected.</small></label>
                <label class="catalogue-replace"><input type="checkbox" name="replace_images" value="1"><span><strong>Replace existing images</strong><small>Requires at least one new image. Existing files will be deleted after the catalogue saves.</small></span></label>
                <p class="catalogue-form-status" data-catalogue-form-status role="status" aria-live="polite"></p>
            </div>
            <div class="catalogue-modal-actions"><button class="secondary-button" type="button" data-catalogue-modal-close>Cancel</button><button class="primary-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="save"></i>Save changes</button></div>
        </form>
    </dialog>
    <dialog class="catalogue-modal catalogue-import-modal" data-catalogue-import-modal>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $escape(updates_csrf_token()) ?>">
            <input type="hidden" name="action" value="import-csv">
            <div class="catalogue-modal-heading"><div><small>BULK CATALOGUE</small><h2>IMPORT CSV</h2></div><button class="icon-button" type="button" data-catalogue-import-close aria-label="Close import dialog"><i data-lucide="x"></i></button></div>
            <div class="catalogue-import-content">
                <p>Each row creates or updates one size. Existing images are kept, ready for upload from the entry's Edit dialog.</p>
                <div class="catalogue-csv-columns"><code>brand</code><code>range</code><code>version</code><code>size</code><code>width</code><code>height</code></div>
                <label>CSV file<input type="file" name="catalogue_csv" accept=".csv,text/csv" required><small>UTF-8 CSV with a header row. Maximum 500 entries and 2 MB.</small></label>
                <a class="text-button" href="/tileimagegen/catalogue/?csv-template=1"><i data-lucide="download"></i>Download CSV template</a>
            </div>
            <div class="catalogue-modal-actions"><button class="secondary-button" type="button" data-catalogue-import-close>Cancel</button><button class="primary-button" type="submit"<?= updates_writes_enabled() ? '' : ' disabled' ?>><i data-lucide="file-up"></i>Import entries</button></div>
        </form>
    </dialog>
</section>
<?php dashboard_footer(); ?>