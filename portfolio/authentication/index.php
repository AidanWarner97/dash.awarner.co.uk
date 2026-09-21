<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/layout.php';
$settings = project_settings('portfolio');
dashboard_header($settings['title'] . ': Authentication', 'portfolio-auth', 'portfolio');
project_placeholder($settings['domain'], $settings['title'], 'ACCESS', 'AUTHENTICATION', 'Portfolio authentication controls will be built here.', 'shield-check');
dashboard_footer();