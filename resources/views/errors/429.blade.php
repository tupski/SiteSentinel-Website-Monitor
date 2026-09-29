<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Too Many Attempts — SiteSentinel</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #f8fafc; color: #0f172a;
               display: flex; min-height: 100vh; margin: 0; align-items: center; justify-content: center; }
        .box { text-align: center; padding: 2rem; }
        h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
        p { color: #475569; margin: 0; }
    </style>
</head>
<body>
    <div class="box">
        <h1>429 — Too Many Attempts</h1>
        <p>{{ $message }}</p>
    </div>
</body>
</html>
