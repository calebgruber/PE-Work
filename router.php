<?php

$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$segments = array_values(array_filter(explode('/', $requestPath), static fn ($segment) => $segment !== ''));
$publicAssetPrefixes = ['/shared/assets/'];
$publicAssetExtensions = ['css', 'gif', 'ico', 'jpeg', 'jpg', 'js', 'png', 'svg', 'webp', 'woff', 'woff2'];
$publicPhpEntrypoints = [
    '/branding_logo.php',
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
    '/' => '/index.php',
    '/branding_logo' => '/branding_logo.php',
    '/export' => '/export.php',
    '/login' => '/login.php',
    '/logout' => '/logout.php',
    '/profile' => '/profile.php',
    '/resource_file' => '/resource_file.php',
    '/settings' => '/settings.php',
    '/setup' => '/setup.php',
    '/show' => '/show.php',
    '/users' => '/users.php',
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
            $extension = strtolower(pathinfo($requestPath, PATHINFO_EXTENSION));
            if (!in_array($extension, $publicAssetExtensions, true)) {
                http_response_code(404);
                echo 'Not Found';
                return true;
            }
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

$mappedEntrypoint = $publicRoutes[$path] ?? null;
if ($mappedEntrypoint !== null && is_file(__DIR__ . $mappedEntrypoint)) {
    $phpTarget = __DIR__ . $mappedEntrypoint;
    require $phpTarget;
    return true;
}

http_response_code(404);
echo 'Not Found';
return true;
