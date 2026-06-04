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
        .nav.wrap { width:min(1160px, calc(100% - 32px)); }
        .nav { display:flex; align-items:center; justify-content:space-between; padding:22px 0; gap:18px; position:relative; }
        .brand { display:flex; align-items:center; gap:10px; color:var(--ink); text-decoration:none; font-size:16px; font-weight:950; }
        .brand img { width:38px; height:38px; border-radius:12px; }
        .navlinks { display:flex; align-items:center; flex-wrap:wrap; gap:18px; color:var(--muted); font-size:14px; }
        .navlinks a { color:inherit; font-weight:800; text-decoration:none; }
        .mobile-menu { display:none; }
        .mobile-menu summary { list-style:none; cursor:pointer; width:42px; height:42px; display:grid; place-items:center; border:1px solid var(--line); background:rgba(255,255,255,.86); border-radius:14px; padding:0; color:#102016; box-shadow:0 12px 30px rgba(24,80,40,.1); }
        .mobile-menu summary::-webkit-details-marker { display:none; }
        .mobile-menu-icon { width:18px; height:14px; display:grid; gap:4px; }
        .mobile-menu-icon span { display:block; height:2px; border-radius:999px; background:#102016; }
        .mobile-menu-panel { position:absolute; right:0; top:70px; z-index:20; display:grid; gap:6px; min-width:210px; padding:10px; background:rgba(255,255,255,.96); border:1px solid var(--line); border-radius:22px; box-shadow:0 24px 60px rgba(24,80,40,.18); backdrop-filter:blur(14px); }
        .mobile-menu-panel a { color:#102016; text-decoration:none; font-weight:850; padding:12px 14px; border-radius:14px; }
        .mobile-menu-panel a:hover { background:#effaf0; }
        main { background:rgba(255,255,255,.84); border:1px solid var(--line); border-radius:28px; box-shadow:0 22px 70px rgba(30,80,45,.12); padding:clamp(24px,5vw,56px); margin:20px 0 48px; }
        h1 { font-size:clamp(34px,6vw,58px); line-height:1; letter-spacing:-.05em; margin:0 0 12px; }
        h2 { margin:34px 0 10px; font-size:24px; letter-spacing:-.02em; }
        p, li { color:var(--muted); }
        .eyebrow { color:var(--green); font-weight:900; text-transform:uppercase; letter-spacing:.12em; font-size:12px; }
        .effective { margin:0 0 24px; color:#708078; }
        .card { border:1px solid var(--line); border-radius:18px; background:#fff; padding:18px; margin:18px 0; }
        footer { color:#778278; font-size:14px; padding:0 0 32px; }
        @media(max-width:620px) { .nav { padding:16px 0; } .navlinks { display:none; } .mobile-menu { display:block; } }
    </style>
</head>
<body>
    @include('partials.public-nav')
    <main class="wrap">
        @yield('content')
    </main>
    <footer class="wrap">© {{ date('Y') }} HeyBean. Questions? Email <a href="mailto:support@heybean.org">support@heybean.org</a>.</footer>
</body>
</html>
