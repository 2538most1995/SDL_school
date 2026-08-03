<!doctype html>
<html lang="th">
<head>
    @php
        $senaBasePath = request()->server('SENA_APP_BASE_PATH');
        $requestBasePath = $senaBasePath !== null
            ? rtrim((string) $senaBasePath, '/')
            : rtrim(preg_replace('#/index\.php$#', '', request()->getBaseUrl()) ?? '', '/');
        if ($requestBasePath === '' && $senaBasePath === null) {
            $requestUriPath = rtrim(parse_url(request()->getRequestUri(), PHP_URL_PATH) ?: '', '/');
            $currentPath = rtrim((string) request()->getPathInfo(), '/');
            if ($currentPath !== '' && str_ends_with($requestUriPath, $currentPath)) {
                $requestBasePath = rtrim(substr($requestUriPath, 0, -strlen($currentPath)), '/');
            } elseif ($currentPath === '') {
                $requestBasePath = $requestUriPath;
            }
        }
        $configuredBasePath = rtrim(parse_url((string) config('app.url'), PHP_URL_PATH) ?: '', '/');
        $appBasePath = $requestBasePath !== '' ? $requestBasePath : $configuredBasePath;

    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="app-base-path" content="{{ $appBasePath }}">
    <meta name="theme-color" content="#0c7656">
    <title>SDL School</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
</head>
<body>
    <div id="app">
        <main class="boot-fallback" aria-live="polite">
            <div class="boot-fallback__mark" aria-hidden="true">S</div>
            <p>กำลังเปิดระบบ SDL School</p>
            <span>หากหน้านี้ไม่เปลี่ยน กรุณารีเฟรชหน้าเว็บหนึ่งครั้ง</span>
        </main>
    </div>
    <noscript><p class="boot-noscript">ระบบนี้ต้องเปิดใช้งาน JavaScript ในเบราว์เซอร์</p></noscript>
</body>
</html>
