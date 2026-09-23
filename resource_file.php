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
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string) filesize($path));
$filename = (string) $resource['original_name'];
$asciiFilename = preg_replace('/[^A-Za-z0-9.\-_ ]/', '_', $filename) ?: 'resource.pdf';
header('Content-Disposition: inline; filename="' . str_replace('"', '', $asciiFilename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
readfile($path);
