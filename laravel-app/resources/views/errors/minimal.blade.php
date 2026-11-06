<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f4f8fa; color: #10213d; }
        main { max-width: 36rem; padding: 2rem; text-align: center; }
        h1 { font-size: 2.5rem; margin-bottom: .5rem; }
        p { line-height: 1.8; }
        @media (prefers-color-scheme: dark) { body { background: #0b1424; color: #eef4ff; } }
    </style>
</head>
<body>
    <main>
        <h1>@yield('code')</h1>
        <p>@yield('message')</p>
    </main>
</body>
</html>
