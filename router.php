<?php

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$segments = array_values(array_filter(explode('/', $path), static fn ($segment) => $segment !== ''));
foreach ($segments as $segment) {
    if ($segment === '.' || $segment === '..') {
        http_response_code(404);
        echo 'Not Found';
        return true;
    }
}
$normalizedPath = '/' . implode('/', $segments);
if ($normalizedPath === '//') {
    $normalizedPath = '/';
}
$path = $normalizedPath === '' ? '/' : $normalizedPath;
$fullPath = __DIR__ . $path;
$publicAssetPrefixes = ['/shared/assets/'];

if (preg_match('#^/(db|storage)(/|$)#', $path)) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

if ($path !== '/' && file_exists($fullPath) && !is_dir($fullPath)) {
    foreach ($publicAssetPrefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return false;
        }
    }

    http_response_code(404);
    echo 'Not Found';
    return true;
}

if ($path === '/') {
    require __DIR__ . '/index.php';
    return true;
}

$phpTarget = __DIR__ . $path . '.php';
if (file_exists($phpTarget)) {
    require $phpTarget;
    return true;
}

http_response_code(404);
echo 'Not Found';
return true;
