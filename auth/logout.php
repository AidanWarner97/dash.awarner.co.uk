<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !auth_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(405);
    exit;
}
auth_logout();