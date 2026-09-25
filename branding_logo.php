<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';

$path = app_logo_uploaded_file_path();
$mimeType = app_logo_uploaded_mime_type();
if ($path === '' || !is_file($path) || !is_allowed_branding_logo_mime_type($mimeType)) {
    http_response_code(404);
    exit('Not Found');
}

header('Content-Type: ' . $mimeType);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
exit;
