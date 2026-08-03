<?php

declare(strict_types=1);

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = rtrim(dirname($scriptName), '/.');
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '';
$projectPath = '/'.rawurlencode(basename(__DIR__));
if ($requestPath === $projectPath || str_starts_with($requestPath, $projectPath.'/')) {
    $basePath = $projectPath;

    // Some shared-hosting rewrites keep the deployment directory in
    // REQUEST_URI. Laravel routes are declared without that prefix, so make
    // the request look as though the front controller lives at the web root.
    $routePath = substr($requestPath, strlen($projectPath)) ?: '/';
    $queryString = parse_url($requestUri, PHP_URL_QUERY);
    $_SERVER['REQUEST_URI'] = $routePath.($queryString !== null ? '?'.$queryString : '');
    $_SERVER['PATH_INFO'] = $routePath;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
} elseif ($basePath !== '' && !str_starts_with($requestPath, $basePath.'/') && $requestPath !== $basePath) {
    // SCRIPT_NAME contains a directory (e.g. /SDL_school/index.php) but the
    // request URI does NOT start with that directory.  This happens on hosts
    // where the document root IS the project folder – SCRIPT_NAME keeps the
    // physical directory name while the browser URL is at the domain root.
    // Reset basePath so that the app does not prefix links with a subdirectory
    // that the browser does not use.
    $basePath = '';
}
$_SERVER['SENA_APP_BASE_PATH'] = $basePath === '' ? '' : '/'.ltrim($basePath, '/');
if ($_SERVER['SENA_APP_BASE_PATH'] !== '' && ! isset($routePath)) {
    $_SERVER['SCRIPT_NAME'] = $_SERVER['SENA_APP_BASE_PATH'].'/index.php';
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
}

require __DIR__.'/laravel-app/public/index.php';

