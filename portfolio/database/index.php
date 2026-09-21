<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/database_browser.php';
$settings = project_settings('portfolio');
dashboard_header($settings['title'] . ': Database', 'portfolio-database', 'portfolio');
render_database_browser('portfolio');
dashboard_footer();