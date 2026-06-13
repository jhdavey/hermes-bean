<!DOCTYPE html>
<html lang="en">
@php($fromFlutter = request()->query('source') === 'flutter')
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pricing | HeyBean</title>
    <meta name="description" content="Choose a HeyBean plan for AI calendar, tasks, reminders, voice chat, and workspace coordination.">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#16a34a">
    <style>
        :root{--ink:#102016;--muted:#647067;--green:#16a34a;--green-dark:#087a35;--cream:#fffaf0;--surface:#fffdf7;--surface2:#f2fbf1;--line:#dce8dd;--gold:#f59e0b;--slate:#26332a}*{box-sizing:border-box}body{margin:0;font-family:ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:radial-gradient(circle at 14% 0,rgba(22,163,74,.18),transparent 30%),linear-gradient(180deg,#fffaf0,#eef8ee 58%,#fffaf5);overflow-x:hidden}a{color:inherit}.wrap{width:min(1240px,calc(100% - 32px));margin:0 auto}.nav.wrap{width:min(1160px,calc(100% - 32px))}.nav{display:flex;align-items:center;justify-content:space-between;padding:22px 0;gap:18px;position:relative}.brand{display:flex;align-items:center;gap:10px;text-decoration:none;font-weight:950}.brand img{width:38px;height:38px;border-radius:12px}.navlinks{display:flex;align-items:center;gap:18px;flex-wrap:wrap;color:var(--muted);font-size:14px}.navlinks a{text-decoration:none;font-weight:800}.mobile-menu{display:none}.mobile-menu summary{list-style:none;cursor:pointer;width:42px;height:42px;display:grid;place-items:center;border:1px solid var(--line);background:rgba(255,255,255,.86);border-radius:14px;padding:0;color:#102016;box-shadow:0 12px 30px rgba(24,80,40,.1)}.mobile-menu-icon{width:18px;height:14px;display:grid;gap:4px}.mobile-menu-icon span{display:block;height:2px;border-radius:999px;background:#102016}.mobile-menu summary::-webkit-details-marker{display:none}.mobile-menu-panel{position:absolute;right:0;top:70px;z-index:20;display:grid;gap:6px;min-width:210px;padding:10px;background:rgba(255,255,255,.96);border:1px solid var(--line);border-radius:22px;box-shadow:0 24px 60px rgba(24,80,40,.18);backdrop-filter:blur(14px)}.mobile-menu-panel a{text-decoration:none;color:#102016;font-weight:850;padding:12px 14px;border-radius:14px}.mobile-menu-panel a:hover{background:#effaf0}.button{border:0;border-radius:16px;background:var(--green);color:white;padding:14px 18px;font-weight:950;font-size:15px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:transform .18s ease,box-shadow .18s ease,background .18s ease}.button:hover{transform:translateY(-1px);box-shadow:0 14px 34px rgba(22,163,74,.24);background:var(--green-dark)}.button.secondary{background:white;color:#102016;border:1px solid var(--line)}.button.secondary:hover{background:#f7fbf5;box-shadow:0 14px 34px rgba(16,32,22,.08)}.button.dark{background:var(--ink)}.button.dark:hover{background:#1c2c21;box-shadow:0 14px 34px rgba(16,32,22,.18)}.hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.78fr);gap:42px;align-items:center;padding:34px 0 42px}.pill{display:inline-flex;align-items:center;gap:8px;border:1px solid #bfe6c8;background:#effaf0;border-radius:999px;padding:8px 12px;color:#087a35;font-weight:950;font-size:13px}.hero h1{font-size:clamp(44px,7vw,82px);line-height:.9;letter-spacing:-.065em;margin:18px 0 16px;color:#0c1c12;max-width:780px}.hero p{font-size:clamp(17px,2vw,22px);line-height:1.48;color:var(--muted);max-width:680px}.hero-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}.trial-note{display:flex;gap:10px;align-items:flex-start;color:#56635c;font-size:14px;font-weight:750;margin-top:16px}.trial-note span{width:24px;height:24px;border-radius:999px;background:#dcfce7;color:#087a35;display:grid;place-items:center;font-weight:950;flex:0 0 24px}.visual{position:relative;min-height:420px;display:grid;place-items:center}.halo{position:absolute;width:420px;height:420px;border-radius:50%;background:radial-gradient(circle,rgba(22,163,74,.22),transparent 68%);filter:blur(10px)}.phone{position:relative;background:#101412;border-radius:48px;padding:12px;box-shadow:0 34px 88px rgba(9,40,20,.31),inset 0 0 0 1px rgba(255,255,255,.16);transform:rotate(1.5deg)}.phone:before{content:"";position:absolute;top:20px;left:50%;transform:translateX(-50%);width:104px;height:28px;border-radius:18px;background:#070a08;z-index:3}.phone img{display:block;width:min(248px,58vw);height:auto;max-height:540px;object-fit:contain;border-radius:38px}.mini-card{position:absolute;right:0;bottom:42px;background:white;border:1px solid var(--line);border-radius:20px;padding:14px 16px;box-shadow:0 18px 48px rgba(20,70,35,.16);font-weight:950;max-width:220px}.mini-card small{display:block;color:var(--muted);font-weight:750;line-height:1.35;margin-top:4px}.pricing{padding:34px 0 72px}.pricing-head{display:block;margin-bottom:18px}.pricing-head h2{font-size:clamp(32px,4.6vw,58px);line-height:.96;letter-spacing:-.055em;margin:0}.pricing-head p{color:var(--muted);line-height:1.5;max-width:430px;margin:10px 0 0}.plans{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;align-items:stretch}.plan{position:relative;background:rgba(255,255,255,.82);border:1px solid var(--line);border-radius:28px;padding:24px;box-shadow:0 16px 48px rgba(24,80,40,.08);display:flex;flex-direction:column;min-height:680px}.plan.popular{background:linear-gradient(180deg,#102016,#182d20);color:white;border-color:rgba(191,230,200,.36);box-shadow:0 28px 78px rgba(16,32,22,.26);transform:translateY(-10px)}.badge{position:absolute;top:18px;right:18px;border-radius:999px;background:#fef3c7;color:#92400e;padding:7px 10px;font-size:12px;font-weight:950}.plan h3{font-size:28px;letter-spacing:-.035em;margin:0 0 8px}.plan .for{color:var(--muted);line-height:1.45;min-height:72px;margin:0 0 18px}.plan.popular .for{color:#cfe8d4}.price{display:flex;align-items:flex-end;gap:6px;margin:8px 0 4px}.amount{font-size:clamp(38px,4vw,52px);line-height:.9;letter-spacing:-.045em;font-weight:950}.period{color:var(--muted);font-weight:850;padding-bottom:6px}.plan.popular .period{color:#b9d6bf}.trial{font-size:13px;color:#087a35;font-weight:950;margin:8px 0 20px}.plan.popular .trial{color:#bbf7d0}.features{list-style:none;padding:0;margin:18px 0 22px;display:grid;gap:11px}.features li{display:flex;gap:10px;line-height:1.35;color:#26332a;font-weight:760}.plan.popular .features li{color:#f1fff4}.features li:before{content:"✓";display:grid;place-items:center;flex:0 0 22px;width:22px;height:22px;border-radius:999px;background:#dcfce7;color:#087a35;font-weight:950;font-size:13px}.plan.popular .features li:before{background:#dcfce7;color:#087a35}.plan .button{width:100%;margin-top:auto}.fine{font-size:12px;color:#778179;line-height:1.5;margin:14px 0 0}.plan.popular .fine{color:#b9d6bf}.compare{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:22px}.info{background:rgba(255,255,255,.76);border:1px solid var(--line);border-radius:26px;padding:22px;box-shadow:0 16px 48px rgba(24,80,40,.07)}.info b{display:block;font-size:18px;margin-bottom:8px}.info p{color:var(--muted);line-height:1.55;margin:0}.footer{padding:40px 0;color:#68766d;display:flex;gap:18px;justify-content:space-between;flex-wrap:wrap;font-size:14px;border-top:1px solid rgba(16,32,22,.08)}.footer a{color:#087a35;font-weight:850;text-decoration:none}@media(max-width:1100px){.plans{grid-template-columns:repeat(2,minmax(0,1fr))}.plan.popular{transform:none}}@media(max-width:920px){.hero,.compare{grid-template-columns:1fr}.visual{min-height:360px}.mini-card{right:10px;bottom:16px}.navlinks{gap:12px;font-size:13px}.phone img{width:min(230px,70vw)}}@media(max-width:620px){.nav{padding:16px 0}.navlinks{display:none}.mobile-menu{display:block}.hero{padding-top:18px}.hero h1{font-size:clamp(42px,14vw,68px)}.hero-actions .button{width:100%}.plans{grid-template-columns:1fr;gap:14px}.plan{min-height:auto;padding:22px}.amount{font-size:46px}.halo{width:320px;height:320px}.mini-card{position:relative;right:auto;bottom:auto;margin-top:-10px}.visual{gap:0}}
        .pricing-page .hero h1{margin-top:0}.pricing-page .pricing-head p{max-width:760px}
        .public-beta-banner{position:sticky;top:0;z-index:25;width:100%;min-height:34px;border-bottom:1px solid rgba(22,163,74,.2);background:linear-gradient(135deg,rgba(22,163,74,.96),rgba(34,197,94,.94));color:#fff;font-size:13px;font-weight:850;letter-spacing:0;text-align:center;box-shadow:0 8px 20px rgba(22,163,74,.16)}.public-beta-banner-inner{width:min(1160px,calc(100% - 32px));min-height:34px;margin:0 auto;display:flex;align-items:center;justify-content:center;padding:8px 0}.public-beta-banner strong{font-weight:950}.public-beta-banner a{color:inherit;font-weight:950;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px}@media(max-width:620px){.public-beta-banner-inner{justify-content:flex-start;text-align:left;line-height:1.35}}
    </style>
</head>
<body class="pricing-page">
    @include('partials.public-beta-banner')
    @include('partials.public-nav')

    <main class="wrap hero">
        <section>
            <h1>Pricing</h1>
            <p>From personal follow-through to full household coordination, Bean brings your calendar, tasks, reminders, chat, and voice help into one calm daily command center.</p>
            <div class="hero-actions">
                <a class="button" href="#plans">Compare plans</a>
                <a class="button secondary" href="/register?plan=premium">Start trial</a>
            </div>
            <div class="trial-note"><span>✓</span><div>Start with a 7-day free trial. Billing begins on day 8 and continues monthly until canceled.</div></div>
            @if ($fromFlutter)
                <div class="trial-note"><span>✓</span><div><strong>Coming from the app?</strong> After upgrading on the site, close and reopen the Flutter app to apply your upgrade.</div></div>
            @endif
        </section>
        <section class="visual" aria-label="HeyBean app preview">
            <div class="halo"></div>
            <div class="phone"><img src="{{ asset('images/heybean-mobile-today-calendar.png') }}?v={{ filemtime(public_path('images/heybean-mobile-today-calendar.png')) }}" alt="HeyBean mobile app showing calendar, tasks, and reminders"></div>
            <div class="mini-card">Built for everyday use<small>Coordinate shared plans, recurring routines, reminders, calendars, and historical context.</small></div>
        </section>
    </main>

    <section class="wrap pricing" id="plans">
        <div class="pricing-head">
            <h2>More context, more coordination, more Bean.</h2>
            <p>Upgrade when you want Bean to handle more of the moving pieces across your calendar, household, reminders, and connected accounts.</p>
        </div>

        <div class="plans">
            <article class="plan">
                <h3>Base</h3>
                <p class="for">For getting your personal day into one organized place.</p>
                <div class="price"><span class="amount">$4.99</span><span class="period">/mo</span></div>
                <p class="trial">7-day free trial, then billed monthly</p>
                <ul class="features">
                    <li>2 workspaces for personal and shared planning</li>
                    <li>Tasks, reminders, and calendar in one daily view</li>
                    <li>Bean chat and voice for everyday requests</li>
                    <li>1 connected calendar</li>
                    <li>Push reminders for the things you cannot miss</li>
                    <li>Recent history so Bean can follow the thread of your day</li>
                    <li>A calm entry point for keeping daily logistics together</li>
                </ul>
                <a class="button secondary" href="/register?plan=base">Start Base trial</a>
                <p class="fine">A simple place to begin with Bean.</p>
            </article>

            <article class="plan popular">
                <span class="badge">Most popular</span>
                <h3>Premium</h3>
                <p class="for">For families and power users who want Bean woven into the daily routine.</p>
                <div class="price"><span class="amount">$19.99</span><span class="period">/mo</span></div>
                <p class="trial">7-day free trial, then billed on day 8</p>
                <ul class="features">
                    <li>5 workspaces for home, work, school, and projects</li>
                    <li>Expanded Bean capacity for everyday planning</li>
                    <li>Push and email reminders working together</li>
                    <li>Recurring tasks and reminders for repeating routines</li>
                    <li>Multiple calendar connections</li>
                    <li>1 year of searchable context and history</li>
                    <li>The best fit for most households and busy personal lives</li>
                </ul>
                <a class="button" href="/register?plan=premium">Start Premium trial</a>
                <p class="fine">Cancel before day 8 to avoid being billed.</p>
            </article>

            <article class="plan">
                <h3>Pro</h3>
                <p class="for">For people who want Bean to run across every workspace, account, and recurring workflow.</p>
                <div class="price"><span class="amount">$49.99</span><span class="period">/mo</span></div>
                <p class="trial">7-day free trial, then billed on day 8</p>
                <ul class="features">
                    <li>Unlimited workspaces for every area of life</li>
                    <li>Maximum Bean capacity for high-volume days</li>
                    <li>More room for connected tools and background work</li>
                    <li>Unlimited connected accounts</li>
                    <li>Full memory and history</li>
                    <li>Priority background work when Bean is handling more</li>
                    <li>Priority support</li>
                </ul>
                <a class="button dark" href="/register?plan=pro">Start Pro trial</a>
                <p class="fine">Built for users who want Bean available across the whole operating system of their day.</p>
            </article>

            <article class="plan">
                <h3>Enterprise</h3>
                <p class="for">For teams and organizations that need custom support, rollout planning, and account-level coordination.</p>
                <div class="price"><span class="amount">Custom</span></div>
                <p class="trial">Contact us for pricing</p>
                <ul class="features">
                    <li>Custom workspace and connected-account needs</li>
                    <li>Admin planning for larger groups</li>
                    <li>Dedicated setup guidance</li>
                    <li>Custom memory and retention discussions</li>
                    <li>Priority support and rollout help</li>
                    <li>Room for future enterprise controls</li>
                    <li>A direct path for teams with special requirements</li>
                </ul>
                <a class="button secondary" href="mailto:support@heybean.org?subject=HeyBean%20Enterprise">Contact us</a>
                <p class="fine">We will help shape the right plan for your team.</p>
            </article>
        </div>

        <div class="compare">
            <div class="info">
                <b>Why upgrade</b>
                <p>Premium and Pro give Bean more room to understand your context, coordinate across spaces, remember what matters, and keep recurring logistics moving.</p>
            </div>
            <div class="info">
                <b>Start with confidence</b>
                <p>Every self-serve plan includes a 7-day free trial, then renews monthly until canceled. Enterprise plans are shaped with your team before billing starts.</p>
            </div>
        </div>
    </section>

    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. AI executive assistance for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a></span></footer>
</body>
</html>
