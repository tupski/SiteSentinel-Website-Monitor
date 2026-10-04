<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Too Many Attempts — SiteSentinel</title>
    @include('partials.theme-bootstrap')
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #f8fafc; color: #0f172a;
               display: flex; min-height: 100vh; margin: 0; align-items: center; justify-content: center; }
        .box { text-align: center; padding: 2rem; }
        h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
        p { color: #475569; margin: 0; }
        html.dark body { background: #0f172a; color: #e2e8f0; }
        html.dark p { color: #94a3b8; }
    </style>
</head>
<body>
    <div class="box">
        <h1>429 — Too Many Attempts</h1>
        <p>You have made too many requests. Please wait a minute and try again.</p>
    </div>
</body>
</html>
