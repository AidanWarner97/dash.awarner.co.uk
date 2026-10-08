<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/database_browser.php';
$settings = project_settings('evolutioncdn');
dashboard_header($settings['title'] . ': Database', 'evolution-database', 'evolutioncdn');
render_database_browser('evolutioncdn');
dashboard_footer();