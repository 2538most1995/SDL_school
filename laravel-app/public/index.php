<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Plesk can preserve the deployment directory in REQUEST_URI even after its
// rewrite reaches this front controller. Normalize it before Request::capture
// so Laravel matches routes such as /auth/login instead of
// /SDL_school/auth/login.
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '';
$deploymentPath = '/'.rawurlencode(basename(dirname(__DIR__, 2)));
if ($requestPath === $deploymentPath || str_starts_with($requestPath, $deploymentPath.'/')) {
    $_SERVER['SENA_APP_BASE_PATH'] = $deploymentPath;
    $routePath = substr($requestPath, strlen($deploymentPath)) ?: '/';
    $queryString = parse_url($requestUri, PHP_URL_QUERY);
    $_SERVER['REQUEST_URI'] = $routePath.($queryString !== null ? '?'.$queryString : '');
    $_SERVER['PATH_INFO'] = $routePath;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
