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
        :root { color-scheme: light; --ink:#1e211e; --muted:#6d736c; --green:#52a869; --paper:#fff; --paper-2:#f7f8f6; --line:rgba(30,33,30,.11); --line-strong:rgba(30,33,30,.2); }
        * { box-sizing: border-box; }
        body { margin:0; font-family:"Plus Jakarta Sans",ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; color:var(--ink); background:var(--paper-2); line-height:1.65; }
        a { color:#087a35; font-weight:700; }
        .wrap { width:min(920px, calc(100% - 32px)); margin:0 auto; }
        .nav.wrap { width:min(1160px, calc(100% - 32px)); }
        .nav { min-height:68px; display:grid; grid-template-columns:minmax(150px,1fr) auto minmax(150px,1fr); align-items:center; padding:12px 0; gap:18px; position:relative; border-bottom:1px solid var(--line); }
        .brand { justify-self:start; display:flex; align-items:center; gap:9px; color:var(--ink); text-decoration:none; font-size:17px; font-weight:700; letter-spacing:-.035em; }
        .brand img { width:30px; height:30px; border-radius:0; object-fit:contain; }
        .navlinks { justify-self:center; display:flex; align-items:center; flex-wrap:wrap; justify-content:center; gap:18px; color:var(--muted); font-size:12px; }
        .navlinks a { color:inherit; font-weight:600; text-decoration:none; }
        .nav-login { justify-self:end; min-width:76px; height:38px; display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--line-strong); border-radius:2px; padding:0 16px; color:var(--ink); background:transparent; text-decoration:none; font-size:12px; font-weight:700; box-shadow:none; }
        .mobile-menu { display:none; }
        .mobile-menu summary { list-style:none; cursor:pointer; width:40px; height:40px; display:grid; place-items:center; border:1px solid var(--line-strong); background:var(--paper); border-radius:0; padding:0; color:var(--ink); box-shadow:none; }
        .mobile-menu summary::-webkit-details-marker { display:none; }
        .mobile-menu-icon { width:18px; height:14px; display:grid; gap:4px; }
        .mobile-menu-icon span { display:block; height:2px; border-radius:999px; background:#102016; }
        .mobile-menu-panel { position:absolute; right:0; top:62px; z-index:20; display:grid; gap:0; min-width:210px; padding:6px 10px; background:var(--paper); border:1px solid var(--line-strong); border-radius:0; box-shadow:none; }
        .mobile-menu-panel a { color:var(--ink); text-decoration:none; font-weight:650; padding:11px 4px; border-radius:0; border-bottom:1px solid var(--line); }
        .mobile-menu-panel a:hover { background:var(--paper-2); }
        main { background:var(--paper); border:0; border-top:1px solid var(--line-strong); border-bottom:1px solid var(--line-strong); border-radius:0; box-shadow:none; padding:clamp(24px,5vw,56px); margin:24px 0 48px; }
        h1 { font-size:clamp(34px,6vw,58px); line-height:1; letter-spacing:-.05em; margin:0 0 12px; }
        h2 { margin:34px 0 10px; font-size:24px; letter-spacing:-.02em; }
        p, li { color:var(--muted); }
        .eyebrow { color:var(--green); font-weight:900; text-transform:uppercase; letter-spacing:.12em; font-size:12px; }
        .effective { margin:0 0 24px; color:#708078; }
        .card { border:0; border-top:1px solid var(--line-strong); border-bottom:1px solid var(--line); border-radius:0; background:transparent; padding:18px 0; margin:18px 0; }
        footer { color:#778278; font-size:14px; padding:0 0 32px; }
        @media(max-width:620px) { .nav { grid-template-columns:1fr auto; padding:16px 0; } .navlinks, .nav-login { display:none; } .mobile-menu { display:block; } }
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
