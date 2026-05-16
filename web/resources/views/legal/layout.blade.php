<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'HeyBean' }}</title>
    <meta name="description" content="{{ $description ?? 'HeyBean legal and support information.' }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site-manifest.json') }}">
    <meta name="theme-color" content="#16a34a">
    <style>
        :root { color-scheme: light; --ink:#102016; --muted:#58645c; --green:#16a34a; --cream:#fbf7ed; --line:#dce8dd; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color:var(--ink); background:linear-gradient(180deg,#fffaf0,#f1faef); line-height:1.65; }
        a { color:#087a35; font-weight:700; }
        .wrap { width:min(920px, calc(100% - 32px)); margin:0 auto; }
        header { padding:28px 0; }
        nav { display:flex; align-items:center; justify-content:space-between; gap:16px; }
        .brand { display:flex; align-items:center; gap:10px; color:var(--ink); text-decoration:none; font-size:18px; }
        .brand img { width:36px; height:36px; border-radius:10px; }
        .links { display:flex; flex-wrap:wrap; gap:14px; font-size:14px; }
        main { background:rgba(255,255,255,.84); border:1px solid var(--line); border-radius:28px; box-shadow:0 22px 70px rgba(30,80,45,.12); padding:clamp(24px,5vw,56px); margin:20px 0 48px; }
        h1 { font-size:clamp(34px,6vw,58px); line-height:1; letter-spacing:-.05em; margin:0 0 12px; }
        h2 { margin:34px 0 10px; font-size:24px; letter-spacing:-.02em; }
        p, li { color:var(--muted); }
        .eyebrow { color:var(--green); font-weight:900; text-transform:uppercase; letter-spacing:.12em; font-size:12px; }
        .effective { margin:0 0 24px; color:#708078; }
        .card { border:1px solid var(--line); border-radius:18px; background:#fff; padding:18px; margin:18px 0; }
        footer { color:#778278; font-size:14px; padding:0 0 32px; }
    </style>
</head>
<body>
    <header class="wrap">
        <nav>
            <a class="brand" href="/">
                <img src="{{ asset('images/bean-logo-color.png') }}" alt="HeyBean logo">
                <strong>HeyBean</strong>
            </a>
            <div class="links">
                <a href="/privacy">Privacy</a>
                <a href="/terms">Terms</a>
                <a href="/support">Support</a>
                <a href="/account-deletion">Account deletion</a>
            </div>
        </nav>
    </header>
    <main class="wrap">
        @yield('content')
    </main>
    <footer class="wrap">© {{ date('Y') }} HeyBean. Questions? Email <a href="mailto:support@heybean.org">support@heybean.org</a>.</footer>
</body>
</html>
