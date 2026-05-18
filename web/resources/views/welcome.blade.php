<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HeyBean — an AI executive assistant for real life</title>
    <meta name="description" content="HeyBean plans your calendar, captures tasks, sets reminders, and coordinates home and work from one focused assistant.">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site-manifest.json') }}">
    <meta name="theme-color" content="#16a34a">
    <style>
        :root{--ink:#102016;--muted:#627066;--green:#16a34a;--green-dark:#087a35;--cream:#fbf7ed;--line:#dce8dd;--gold:#f59e0b}*{box-sizing:border-box}body{margin:0;font-family:ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:radial-gradient(circle at top left,rgba(22,163,74,.18),transparent 30%),linear-gradient(180deg,#fffaf0,#edf8ee 55%,#fffaf5);overflow-x:hidden}a{color:inherit}.wrap{width:min(1160px,calc(100% - 32px));margin:0 auto}.nav{display:flex;align-items:center;justify-content:space-between;padding:22px 0;gap:18px;position:relative}.brand{display:flex;align-items:center;gap:10px;text-decoration:none;font-weight:900}.brand img{width:38px;height:38px;border-radius:12px}.navlinks{display:flex;gap:18px;align-items:center;flex-wrap:wrap;color:var(--muted);font-size:14px}.navlinks a{text-decoration:none}.mobile-menu{display:none}.mobile-menu summary{list-style:none;cursor:pointer;border:1px solid var(--line);background:rgba(255,255,255,.86);border-radius:999px;padding:10px 14px;font-size:14px;font-weight:900;color:#102016;box-shadow:0 12px 30px rgba(24,80,40,.1)}.mobile-menu summary::-webkit-details-marker{display:none}.mobile-menu-panel{position:absolute;right:0;top:70px;z-index:20;display:grid;gap:6px;min-width:210px;padding:10px;background:rgba(255,255,255,.96);border:1px solid var(--line);border-radius:22px;box-shadow:0 24px 60px rgba(24,80,40,.18);backdrop-filter:blur(14px)}.mobile-menu-panel a{text-decoration:none;color:#102016;font-weight:850;padding:12px 14px;border-radius:14px}.mobile-menu-panel a:hover{background:#effaf0}.pill{display:inline-flex;align-items:center;gap:8px;border:1px solid #bfe6c8;background:#effaf0;border-radius:999px;padding:8px 12px;color:#087a35;font-weight:900;font-size:13px}.hero{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(320px,.95fr);gap:46px;align-items:center;padding:34px 0 72px}.hero h1{font-size:clamp(48px,8vw,92px);line-height:.88;letter-spacing:-.075em;margin:18px 0;color:#0c1c12}.hero p{font-size:clamp(18px,2.4vw,23px);line-height:1.45;color:var(--muted);max-width:660px}.hero-voice{font-size:clamp(30px,4.8vw,54px)!important;line-height:.94!important;letter-spacing:-.06em;color:#0c1c12!important;margin:28px 0 0!important;max-width:620px}.form{display:flex;gap:10px;background:white;border:1px solid var(--line);padding:12px;border-radius:24px;box-shadow:0 18px 50px rgba(24,80,40,.12);max-width:560px;margin-bottom:6px;min-height:72px;align-items:center}.form input{flex:1;border:0;outline:0;font-size:16px;padding:14px 14px;min-width:180px;min-height:48px}.button,button{border:0;border-radius:16px;background:var(--green);color:white;padding:14px 18px;font-weight:900;font-size:15px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.button.secondary{background:white;color:#102016;border:1px solid var(--line)}.micro{font-size:13px!important;color:#758177!important}.proof{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}.proof span{background:rgba(255,255,255,.72);border:1px solid var(--line);border-radius:999px;padding:9px 12px;font-size:13px;font-weight:800}.phone-wrap{position:relative;display:grid;place-items:center}.halo{position:absolute;width:520px;height:520px;border-radius:50%;background:radial-gradient(circle,rgba(22,163,74,.26),transparent 68%);filter:blur(8px)}.phone{position:relative;background:#101412;border-radius:54px;padding:14px;box-shadow:0 36px 90px rgba(9,40,20,.35),inset 0 0 0 1px rgba(255,255,255,.18);transform:rotate(2deg)}.phone:before{content:"";position:absolute;top:22px;left:50%;transform:translateX(-50%);width:118px;height:31px;border-radius:18px;background:#070a08;z-index:3}.phone img{display:block;width:min(306px,60vw);height:auto;max-height:665px;aspect-ratio:auto;object-fit:contain;border-radius:42px}.callout{position:absolute;background:white;border:1px solid var(--line);border-radius:18px;padding:12px 14px;box-shadow:0 18px 45px rgba(20,70,35,.18);font-weight:900}.callout small{display:block;color:var(--muted);font-weight:700}.c1{right:-12px;top:120px}.c2{left:-24px;bottom:132px}.c2 .pointer{position:absolute;left:76%;bottom:-32px;font-size:18px;line-height:1}.section{padding:72px 0;border-top:1px solid rgba(16,32,22,.08)}.section h2{font-size:clamp(34px,5vw,58px);line-height:.96;letter-spacing:-.055em;margin:0 0 16px}.lead{font-size:20px;color:var(--muted);max-width:720px}.logistics-layout{display:grid;grid-template-columns:minmax(0,1.04fr) minmax(320px,.96fr);gap:28px;align-items:start;margin-top:30px}.chat-mockup{position:relative;display:grid;place-items:center;min-height:100%;padding:6px 12px 12px;background:radial-gradient(circle at 50% 44%,rgba(22,163,74,.18),transparent 62%)}.chat-phone{position:relative;width:min(345px,100%);background:#101412;border-radius:54px;padding:13px;box-shadow:0 34px 90px rgba(9,40,20,.32),inset 0 0 0 1px rgba(255,255,255,.18)}.chat-art{position:relative;overflow:hidden;border-radius:42px;line-height:0;background:#f4faf2}.chat-base{display:block;width:100%;height:auto;aspect-ratio:589/1280;object-fit:contain}.chat-screen{position:absolute;inset:0;overflow:hidden;color:#1e2733;line-height:normal;font-family:ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.chat-overlay{position:absolute;left:4.75%;right:4.75%;top:20%;bottom:31%;background:#f4faf2;display:grid;gap:5px;align-content:start;padding-top:0}.bubble{min-width:0;max-width:100%;border-radius:18px;padding:8px 9px;line-height:1.22;box-shadow:0 6px 18px rgba(24,80,40,.06);font-size:10px;overflow-wrap:anywhere;word-break:normal}.bubble.user{background:#12b65a;color:white;margin-left:12px;border-bottom-right-radius:8px}.bubble.bean{background:rgba(255,255,255,.92);border:1px solid #e1e9e0;color:#1e2733;margin-right:0;border-bottom-left-radius:8px}.bubble.progress{background:#e8efea;color:#65778c;border:1px solid #d5dfd8;font-weight:850;justify-self:start;width:auto;box-shadow:none}.bubble b{display:block;margin-bottom:3px;color:#129c50}.bubble.user b{color:white}.plan-list{display:grid;gap:5px;margin:7px 0 0;padding:0;list-style:none}.plan-list li{display:flex;gap:6px;align-items:flex-start;background:#f6faf3;border:1px solid #e1e9e0;border-radius:12px;padding:6px 7px}.plan-list li>span:first-child{color:#12b65a;font-weight:950}.chat-actions{display:flex;gap:5px;flex-wrap:wrap;margin-top:7px}.chat-actions span{border:1px solid #bfe6c8;background:#effaf0;border-radius:999px;padding:5px 7px;color:#129c50;font-size:9.7px;font-weight:900}.logistics-layout .flow{grid-template-columns:1fr;margin-top:0}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:28px}.card{background:rgba(255,255,255,.78);border:1px solid var(--line);border-radius:28px;padding:24px;box-shadow:0 16px 50px rgba(24,80,40,.08)}.card b{display:block;font-size:19px;margin-bottom:8px}.card p{color:var(--muted);line-height:1.55}.flow{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:26px}.step{background:#102016;color:white;border-radius:26px;padding:24px}.step span{display:grid;place-items:center;width:34px;height:34px;border-radius:50%;background:#16a34a;font-weight:900;margin-bottom:22px}.step p{color:#dbe8dd}.quote{background:#102016;color:white;border-radius:36px;padding:38px;margin-top:28px;display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center}.quote p{font-size:24px;line-height:1.35;margin:0;color:#effaf0}.footer{padding:40px 0;color:#68766d;display:flex;gap:18px;justify-content:space-between;flex-wrap:wrap;font-size:14px}.footer a{color:#087a35;font-weight:800;text-decoration:none}@media(max-width:860px){.hero,.grid,.flow,.quote,.logistics-layout{grid-template-columns:1fr}.nav{padding:16px 0}.navlinks{display:none}.mobile-menu{display:block}.form{flex-direction:column;align-items:stretch;padding:14px;min-height:132px}.form input{min-height:56px}.form button{min-height:52px}.phone{transform:none}.phone img{width:min(286px,76vw);height:auto;max-height:620px;max-width:82vw}.halo{width:360px;height:360px}.callout{display:block;transform:scale(.86);transform-origin:center;z-index:4;padding:10px 12px}.c1{right:4px;top:74px}.c2{left:-14px;bottom:102px}.c2 .pointer{left:63%;bottom:-20px}}
        .signup-modal{position:fixed;inset:0;z-index:50;display:grid;place-items:center;padding:24px;background:rgba(16,32,22,.46);backdrop-filter:blur(14px)}.signup-modal-card{width:min(520px,100%);border:1px solid rgba(191,230,200,.9);border-radius:34px;background:linear-gradient(180deg,#fffdf7,#f2fbf1);box-shadow:0 34px 100px rgba(16,32,22,.25);padding:32px;text-align:center}.signup-modal-icon{width:58px;height:58px;margin:0 auto 16px;display:grid;place-items:center;border-radius:20px;background:#dcfce7;color:#087a35;font-size:28px;font-weight:950}.signup-modal-card h2{margin:0 0 10px;font-size:clamp(28px,5vw,42px);line-height:1;letter-spacing:-.045em}.signup-modal-card p{margin:0 auto 22px;color:var(--muted);font-size:17px;line-height:1.55;max-width:430px}.signup-modal-card .button{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border-radius:999px;padding:13px 18px;background:var(--ink);color:white;font-weight:900}
    </style>
</head>
<body>
    <header class="wrap nav">
        <a class="brand" href="/"><img src="{{ asset('images/bean-logo.png') }}" alt="HeyBean logo">HeyBean</a>
        <nav class="navlinks" aria-label="Primary navigation"><a href="#how">How it works</a><a href="#features">Features</a></nav>
        <details class="mobile-menu">
            <summary aria-label="Open menu">Menu</summary>
            <div class="mobile-menu-panel">
                <a href="#how">How it works</a>
                <a href="#features">Features</a>
            </div>
        </details>
    </header>

    <main class="wrap hero">
        <section>
            <span class="pill">● First 100 early-access invites now opening</span>
            <h1>Meet Bean, your new assistant for real-life</h1>
            <p>Bean helps you manage your calendar, keeps track of tasks, set reminders, and keeps you moving instead of getting stuck in the weeds.</p>
            <form class="form" id="early-access" method="POST" action="{{ route('early-access.store') }}">
                @csrf
                <input type="email" name="email" required placeholder="you@example.com" aria-label="Email address">
                <button type="submit">Get Early Access</button>
            </form>
            @if (session('early_access_status'))
                <p class="micro"><strong>You’re on the list — thank you.</strong></p>
            @else
                <p class="micro">No spam. Private beta invites only. We’ll only use your email for HeyBean launch updates.</p>
            @endif
            <div class="proof"><span>Voice-first requests</span><span>Calendar sync-ready</span><span>Approval guardrails</span><span>Private + shared dashboard</span><span>Private by design</span></div>
            <p class="hero-voice"><strong>Just say “Hey, Bean…!”</strong></p>
        </section>
        <section class="phone-wrap" aria-label="HeyBean mobile Today screenshot">
            <div class="halo"></div>
            <div class="phone"><img src="{{ asset('images/heybean-mobile-today-calendar.png') }}?v={{ filemtime(public_path('images/heybean-mobile-today-calendar.png')) }}" alt="HeyBean mobile app screenshot showing seeded events on the Today daily calendar"></div>
            <div class="callout c1">3 events planned<small>calendar updated</small></div>
            <div class="callout c2">Voice-first control<small>Hold the button and say “Hey, Bean…!” <span class="pointer">👇</span></small></div>
        </section>
    </main>

    <section class="wrap section" id="how">
        <h2>Bean manages the logistics, so you stay ahead</h2>
        <p class="lead">Bean turns “Hey Bean…” voice requests into a structured day — with the calendar, tasks, reminders, and household context kept together.</p>
        <div class="logistics-layout">
            <div class="chat-mockup" aria-label="Bean planning chat mockup">
                <div class="chat-phone" aria-label="Bean chat mockup matching real app screen">
                    <div class="chat-art">
                        <img class="chat-base" src="{{ asset('images/bean-chat-app-screen.jpg') }}" width="589" height="1280" alt="Real Bean app screen mockup background">
                        <div class="chat-screen" aria-label="Current Bean chat messages over real app screen">
                            <div class="chat-overlay">
                                <div class="bubble user"><b>Harley</b>Hey Bean, plan next week around school drop-off, two workouts, Lauren’s dinner Thursday, investor follow-ups, groceries, and launch prep. Keep Friday afternoon open if you can.</div>
                                <div class="bubble bean progress">Working… checking calendar, tasks, reminders, and household context.</div>
                                <div class="bubble bean"><b>Bean</b>I mapped the week, protected Friday afternoon, and split the work into calendar blocks, tasks, and reminders.</div>
                                <div class="bubble bean"><b>Draft plan</b><ul class="plan-list"><li><span>✓</span><span>Moved deep work to Mon/Wed morning after drop-off.</span></li><li><span>✓</span><span>Added workouts Tue + Sat with prep reminders.</span></li><li><span>✓</span><span>Staged groceries, dinner, and launch tasks by household/work.</span></li></ul><div class="chat-actions"><span>Approve calendar changes</span><span>Review tasks</span></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flow"><div class="step"><span>1</span><b>Ask naturally</b><p>“Hey Bean, move my focus block to 3, remind Sam about cleats, and add groceries to Household.”</p></div><div class="step"><span>2</span><b>Bean organizes</b><p>Calendar events, tasks, reminders, and workspace context update from one conversation.</p></div><div class="step"><span>3</span><b>You stay in control</b><p>Sensitive actions wait for approval so Bean can move fast without surprising you.</p></div></div>
        </div>
    </section>

    <section class="wrap section" id="features">
        <h2>Built for the real day, not a perfect day.</h2>
        <div class="grid"><div class="card"><b>Calendar command center</b><p>Fast daily timeline, critical count, all-day events, connected calendar sync, and quick event editing.</p></div><div class="card"><b>Tasks + reminders</b><p>Capture the things that fall between apps, color-code them by category, and keep today’s priorities visible.</p></div><div class="card"><b>Household workspaces</b><p>Separate personal and shared contexts, invite members, and sync the right items to the right space.</p></div><div class="card"><b>Bean chat</b><p>Type or speak “Hey Bean…” and watch progress inline without losing the focused mobile layout.</p></div><div class="card"><b>Approval guardrails</b><p>Bean can draft and stage actions while letting you approve sensitive changes before they happen.</p></div><div class="card"><b>Privacy controls</b><p>Export your data, delete your account, disconnect calendar sync, and access policy links from the app.</p></div></div>
        <div class="quote"><p>“Done — calendar updated, reminder created, household task synced.”</p><a class="button" href="#early-access">Get early access</a></div>
    </section>

    @if (session('early_access_status'))
        <div class="signup-modal" role="dialog" aria-modal="true" aria-labelledby="signup-modal-title">
            <div class="signup-modal-card">
                <div class="signup-modal-icon" aria-hidden="true">✓</div>
                <h2 id="signup-modal-title">Thank you for signing up!</h2>
                <p>We’ll send you an email as soon as we can share the app with you! We look forward to your help with making Bean great!</p>
                <a class="button" href="/">Sounds good</a>
            </div>
        </div>
    @endif

    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. AI executive assistance for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a></span></footer>
</body>
</html>
