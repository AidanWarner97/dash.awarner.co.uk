<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/layout.php';
$settings = project_settings('portfolio');
dashboard_header($settings['title'] . ': Overview', 'portfolio-overview', 'portfolio');
project_placeholder($settings['domain'], $settings['title'], 'PROJECT HOME', 'OVERVIEW', 'The portfolio project overview will be designed in a later stage.', 'house');
dashboard_footer();