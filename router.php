<?php

$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$segments = array_values(array_filter(explode('/', $requestPath), static fn ($segment) => $segment !== ''));
$publicAssetPrefixes = ['/shared/assets/'];
$publicPhpEntrypoints = [
    '/export.php',
    '/index.php',
    '/login.php',
    '/logout.php',
    '/profile.php',
    '/resource_file.php',
    '/settings.php',
    '/setup.php',
    '/show.php',
    '/users.php',
];
$publicRoutes = [
    '/',
    '/export',
    '/login',
    '/logout',
    '/profile',
    '/resource_file',
    '/settings',
    '/setup',
    '/show',
    '/users',
];
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
$fullPath = __DIR__ . $requestPath;

if (preg_match('#^/(db|storage|tests)(/|$)#', $path) || (str_starts_with($path, '/shared/') && !str_starts_with($path, '/shared/assets/'))) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

if ($requestPath !== '/' && file_exists($fullPath) && !is_dir($fullPath)) {
    foreach ($publicAssetPrefixes as $prefix) {
        if (str_starts_with($requestPath, $prefix)) {
            return false;
        }
    }

    if (str_ends_with($requestPath, '.php') && in_array($requestPath, $publicPhpEntrypoints, true)) {
        require $fullPath;
        return true;
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
if (in_array($path, $publicRoutes, true) && file_exists($phpTarget)) {
    require $phpTarget;
    return true;
}

http_response_code(404);
echo 'Not Found';
return true;
