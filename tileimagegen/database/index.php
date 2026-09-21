<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/database_browser.php';
$settings = project_settings('tileimagegen');
dashboard_header($settings['title'] . ': Database', 'tile-database', 'tileimagegen');
render_database_browser('tileimagegen');
dashboard_footer();