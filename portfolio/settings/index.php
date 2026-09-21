<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/project_settings.php';

updates_start_session();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	try {
		if (!updates_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('This form session has expired. Refresh the page and try again.');
		project_settings_save('portfolio', $_POST);
		header('Location: /portfolio/settings/?saved=1');
		exit;
	} catch (Throwable $exception) {
		$GLOBALS['project_settings_error'] = $exception->getMessage();
	}
}

dashboard_header('Personal Portfolio: Settings', 'portfolio-settings', 'portfolio');
render_project_settings('portfolio');
dashboard_footer();