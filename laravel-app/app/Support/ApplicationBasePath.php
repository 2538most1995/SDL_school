<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ApplicationBasePath
{
    public static function resolve(Request $request): string
    {
        $serverBasePath = $request->server('SENA_APP_BASE_PATH');
        $requestBasePath = $serverBasePath !== null
            ? rtrim((string) $serverBasePath, '/')
            : rtrim(preg_replace('#/index\.php$#', '', $request->getBaseUrl()) ?? '', '/');

        if ($requestBasePath === '' && $serverBasePath === null) {
            $requestUriPath = rtrim(parse_url($request->getRequestUri(), PHP_URL_PATH) ?: '', '/');
            $currentPath = rtrim($request->getPathInfo(), '/');
            if ($currentPath !== '' && str_ends_with($requestUriPath, $currentPath)) {
                $requestBasePath = rtrim(substr($requestUriPath, 0, -strlen($currentPath)), '/');
            } elseif ($currentPath === '') {
                $requestBasePath = $requestUriPath;
            }
        }

        $configuredBasePath = rtrim(parse_url((string) config('app.url'), PHP_URL_PATH) ?: '', '/');

        return $requestBasePath !== '' ? $requestBasePath : $configuredBasePath;
    }
}
