<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';

if (!schema_ready()) {
    http_response_code(404);
    exit('Not found');
}

$resourceId = (int) ($_GET['id'] ?? 0);
$resource = find_resource($resourceId);
if (!$resource) {
    http_response_code(404);
    exit('Not found');
}

$path = resource_path($resource);
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode($resource['original_name']) . '"');
readfile($path);
