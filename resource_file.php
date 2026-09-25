<?php

require_once __DIR__ . '/shared/config.php';
require_once __DIR__ . '/shared/db.php';
require_once __DIR__ . '/shared/app.php';

if (!resource_schema_ready()) {
    http_response_code(404);
    exit('Not found');
}

$resourceIdParam = $_GET['id'] ?? null;
if (is_array($resourceIdParam)) {
    http_response_code(400);
    exit('Bad request');
}
$resourceIdParam = trim((string) $resourceIdParam);
if ($resourceIdParam === '' || !ctype_digit($resourceIdParam) || (int) $resourceIdParam <= 0) {
    http_response_code(400);
    exit('Bad request');
}
$resourceId = (int) $resourceIdParam;
$resource = find_resource($resourceId);
if (!$resource) {
    http_response_code(404);
    exit('Not found');
}

$currentUser = current_user();
$hasAdminSession = $currentUser && empty($currentUser['is_test_user']) && is_admin($currentUser);
$providedToken = trim((string) ($_SERVER['HTTP_X_RESOURCE_TOKEN'] ?? ''));
$providedExpiry = trim((string) ($_SERVER['HTTP_X_RESOURCE_EXPIRES'] ?? ''));
$expiresAt = ctype_digit($providedExpiry) ? (int) $providedExpiry : 0;
if (
    !$hasAdminSession
    && (
        $providedToken === ''
        || $expiresAt < time()
        || $expiresAt > time() + 3600
        || !hash_equals(resource_access_token($resource, $expiresAt), $providedToken)
    )
) {
    http_response_code(403);
    exit('Forbidden');
}

$path = '';
try {
    $path = resource_path($resource);
} catch (RuntimeException $e) {
    http_response_code(404);
    exit('Not found');
}
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$mimeType = detected_upload_mime_type($path);
$storedMimeType = (string) ($resource['mime_type'] ?? '');
$effectiveMimeType = $mimeType;
if (
    $effectiveMimeType === ''
    || (!is_allowed_pdf_mime_type($effectiveMimeType) && !is_allowed_image_mime_type($effectiveMimeType))
) {
    $effectiveMimeType = $storedMimeType;
}
$extension = strtolower(pathinfo((string) ($resource['original_name'] ?? $resource['stored_name'] ?? ''), PATHINFO_EXTENSION));
if (!resource_file_is_valid($path, $effectiveMimeType, $extension)) {
    http_response_code(404);
    exit('Not found');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . ($effectiveMimeType !== '' ? $effectiveMimeType : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Length: ' . (string) filesize($path));
$filename = (string) $resource['original_name'];
$asciiFilename = preg_replace('/[^A-Za-z0-9.\-_ ]/', '_', $filename) ?: 'resource';
$disposition = isset($_GET['download']) && $_GET['download'] === '1' ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $asciiFilename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
readfile($path);
exit;
