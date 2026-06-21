<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Household invite unavailable</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f1f7ee; color: #1f2937; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .card { width: min(440px, calc(100vw - 32px)); background: #fff; border: 1px solid rgba(148, 163, 184, .22); border-radius: 24px; padding: 28px; box-shadow: 0 20px 60px rgba(22, 163, 74, .12); text-align: center; }
        h1 { margin: 0 0 8px; font-size: 1.45rem; }
        p { color: #64748b; line-height: 1.5; }
        .button { display: inline-flex; justify-content: center; align-items: center; margin-top: 18px; width: 100%; border-radius: 999px; padding: 13px 16px; background: #16a34a; color: #fff; font-weight: 800; text-decoration: none; box-sizing: border-box; }
    </style>
</head>
<body>
    <main class="card">
        <h1>Invite could not be accepted</h1>
        <p>{{ $message }}</p>
        <a class="button" href="heybean://login">Open HeyBean</a>
    </main>
</body>
</html>
