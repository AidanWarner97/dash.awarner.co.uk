<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/layout.php';
$settings = project_settings('portfolio');
dashboard_header($settings['title'] . ': Logs', 'portfolio-logs', 'portfolio');
project_placeholder($settings['domain'], $settings['title'], 'ACTIVITY', 'LOGS', 'Portfolio application and audit logs will be built here.', 'scroll-text');
dashboard_footer();