<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/project_settings.php';

updates_start_session();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!updates_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('This form session has expired. Refresh the page and try again.');
        project_settings_save('tileimagegen', $_POST);
        header('Location: /tileimagegen/settings/?saved=1');
        exit;
    } catch (Throwable $exception) {
        $GLOBALS['project_settings_error'] = $exception->getMessage();
    }
}

dashboard_header('Tile Image Generator: Settings', 'tile-settings', 'tileimagegen');
render_project_settings('tileimagegen');
dashboard_footer();