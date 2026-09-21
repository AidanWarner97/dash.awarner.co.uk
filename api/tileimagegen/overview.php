<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/tileimagegen.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

auth_require();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo json_encode(tileimagegen_overview_data((int) ($_GET['period'] ?? 7)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);