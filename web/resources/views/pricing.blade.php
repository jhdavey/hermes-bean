<!DOCTYPE html>
<html lang="en">
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
        :root{--ink:#102016;--muted:#647067;--green:#16a34a;--green-dark:#087a35;--cream:#fffaf0;--surface:#fffdf7;--surface2:#f2fbf1;--line:#dce8dd;--gold:#f59e0b;--slate:#26332a}*{box-sizing:border-box}body{margin:0;font-family:ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:radial-gradient(circle at 14% 0,rgba(22,163,74,.18),transparent 30%),linear-gradient(180deg,#fffaf0,#eef8ee 58%,#fffaf5);overflow-x:hidden}a{color:inherit}.wrap{width:min(1180px,calc(100% - 32px));margin:0 auto}.nav{display:flex;align-items:center;justify-content:space-between;padding:22px 0;gap:18px}.brand{display:flex;align-items:center;gap:10px;text-decoration:none;font-weight:950}.brand img{width:38px;height:38px;border-radius:12px}.navlinks{display:flex;align-items:center;gap:18px;color:var(--muted);font-size:14px}.navlinks a{text-decoration:none;font-weight:800}.button{border:0;border-radius:16px;background:var(--green);color:white;padding:14px 18px;font-weight:950;font-size:15px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:transform .18s ease,box-shadow .18s ease,background .18s ease}.button:hover{transform:translateY(-1px);box-shadow:0 14px 34px rgba(22,163,74,.24);background:var(--green-dark)}.button.secondary{background:white;color:#102016;border:1px solid var(--line)}.button.secondary:hover{background:#f7fbf5;box-shadow:0 14px 34px rgba(16,32,22,.08)}.button.dark{background:var(--ink)}.button.dark:hover{background:#1c2c21;box-shadow:0 14px 34px rgba(16,32,22,.18)}.hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.78fr);gap:42px;align-items:center;padding:34px 0 42px}.pill{display:inline-flex;align-items:center;gap:8px;border:1px solid #bfe6c8;background:#effaf0;border-radius:999px;padding:8px 12px;color:#087a35;font-weight:950;font-size:13px}.hero h1{font-size:clamp(44px,7vw,82px);line-height:.9;letter-spacing:-.065em;margin:18px 0 16px;color:#0c1c12;max-width:780px}.hero p{font-size:clamp(17px,2vw,22px);line-height:1.48;color:var(--muted);max-width:680px}.hero-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}.trial-note{display:flex;gap:10px;align-items:flex-start;color:#56635c;font-size:14px;font-weight:750;margin-top:16px}.trial-note span{width:24px;height:24px;border-radius:999px;background:#dcfce7;color:#087a35;display:grid;place-items:center;font-weight:950;flex:0 0 24px}.visual{position:relative;min-height:420px;display:grid;place-items:center}.halo{position:absolute;width:420px;height:420px;border-radius:50%;background:radial-gradient(circle,rgba(22,163,74,.22),transparent 68%);filter:blur(10px)}.phone{position:relative;background:#101412;border-radius:48px;padding:12px;box-shadow:0 34px 88px rgba(9,40,20,.31),inset 0 0 0 1px rgba(255,255,255,.16);transform:rotate(1.5deg)}.phone:before{content:"";position:absolute;top:20px;left:50%;transform:translateX(-50%);width:104px;height:28px;border-radius:18px;background:#070a08;z-index:3}.phone img{display:block;width:min(248px,58vw);height:auto;max-height:540px;object-fit:contain;border-radius:38px}.mini-card{position:absolute;right:0;bottom:42px;background:white;border:1px solid var(--line);border-radius:20px;padding:14px 16px;box-shadow:0 18px 48px rgba(20,70,35,.16);font-weight:950;max-width:220px}.mini-card small{display:block;color:var(--muted);font-weight:750;line-height:1.35;margin-top:4px}.pricing{padding:34px 0 72px}.pricing-head{display:flex;justify-content:space-between;align-items:end;gap:20px;margin-bottom:18px}.pricing-head h2{font-size:clamp(32px,4.6vw,58px);line-height:.96;letter-spacing:-.055em;margin:0}.pricing-head p{color:var(--muted);line-height:1.5;max-width:430px;margin:0}.plans{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;align-items:stretch}.plan{position:relative;background:rgba(255,255,255,.82);border:1px solid var(--line);border-radius:28px;padding:24px;box-shadow:0 16px 48px rgba(24,80,40,.08);display:flex;flex-direction:column;min-height:640px}.plan.popular{background:linear-gradient(180deg,#102016,#182d20);color:white;border-color:rgba(191,230,200,.36);box-shadow:0 28px 78px rgba(16,32,22,.26);transform:translateY(-10px)}.badge{position:absolute;top:18px;right:18px;border-radius:999px;background:#fef3c7;color:#92400e;padding:7px 10px;font-size:12px;font-weight:950}.plan h3{font-size:28px;letter-spacing:-.035em;margin:0 0 8px}.plan .for{color:var(--muted);line-height:1.45;min-height:52px;margin:0 0 18px}.plan.popular .for{color:#cfe8d4}.price{display:flex;align-items:flex-end;gap:6px;margin:8px 0 4px}.amount{font-size:52px;line-height:.9;letter-spacing:-.055em;font-weight:950}.period{color:var(--muted);font-weight:850;padding-bottom:6px}.plan.popular .period{color:#b9d6bf}.trial{font-size:13px;color:#087a35;font-weight:950;margin:8px 0 20px}.plan.popular .trial{color:#bbf7d0}.features{list-style:none;padding:0;margin:18px 0 22px;display:grid;gap:11px}.features li{display:flex;gap:10px;line-height:1.35;color:#26332a;font-weight:760}.plan.popular .features li{color:#f1fff4}.features li:before{content:"✓";display:grid;place-items:center;flex:0 0 22px;width:22px;height:22px;border-radius:999px;background:#dcfce7;color:#087a35;font-weight:950;font-size:13px}.plan.popular .features li:before{background:#dcfce7;color:#087a35}.plan .button{width:100%;margin-top:auto}.fine{font-size:12px;color:#778179;line-height:1.5;margin:14px 0 0}.plan.popular .fine{color:#b9d6bf}.compare{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:22px}.info{background:rgba(255,255,255,.76);border:1px solid var(--line);border-radius:26px;padding:22px;box-shadow:0 16px 48px rgba(24,80,40,.07)}.info b{display:block;font-size:18px;margin-bottom:8px}.info p{color:var(--muted);line-height:1.55;margin:0}.footer{padding:40px 0;color:#68766d;display:flex;gap:18px;justify-content:space-between;flex-wrap:wrap;font-size:14px;border-top:1px solid rgba(16,32,22,.08)}.footer a{color:#087a35;font-weight:850;text-decoration:none}@media(max-width:920px){.hero,.plans,.compare{grid-template-columns:1fr}.pricing-head{display:block}.pricing-head p{margin-top:10px}.plan.popular{transform:none}.visual{min-height:360px}.mini-card{right:10px;bottom:16px}.navlinks{gap:12px;font-size:13px}.phone img{width:min(230px,70vw)}}@media(max-width:620px){.nav{align-items:flex-start}.navlinks{flex-wrap:wrap;justify-content:flex-end}.hero{padding-top:18px}.hero h1{font-size:clamp(42px,14vw,68px)}.hero-actions .button{width:100%}.plans{gap:14px}.plan{min-height:auto;padding:22px}.amount{font-size:46px}.mini-card{position:relative;right:auto;bottom:auto;margin-top:-10px}.visual{gap:0}}
    </style>
</head>
<body>
    <header class="wrap nav">
        <a class="brand" href="/"><img src="{{ asset('images/bean-logo.png') }}" alt="HeyBean logo">HeyBean</a>
        <nav class="navlinks" aria-label="Primary navigation">
            <a href="/">Home</a>
            <a href="/login">Log in</a>
        </nav>
    </header>

    <main class="wrap hero">
        <section>
            <span class="pill">7-day free trial on paid plans</span>
            <h1>Pick how much help Bean can give your day.</h1>
            <p>Start light, upgrade when Bean becomes part of your daily rhythm. Every plan includes the core calendar, task, reminder, chat, and voice experience.</p>
            <div class="hero-actions">
                <a class="button" href="#plans">Compare plans</a>
                <a class="button secondary" href="/register?plan=premium">Start Premium trial</a>
            </div>
            <div class="trial-note"><span>✓</span><div>During beta, all users get unlimited use for free. The 7-day trial and day-8 billing will apply after beta once paid billing opens.</div></div>
        </section>
        <section class="visual" aria-label="HeyBean app preview">
            <div class="halo"></div>
            <div class="phone"><img src="{{ asset('images/heybean-mobile-today-calendar.png') }}?v={{ filemtime(public_path('images/heybean-mobile-today-calendar.png')) }}" alt="HeyBean mobile app showing calendar, tasks, and reminders"></div>
            <div class="mini-card">Premium is built for everyday household use<small>More workspaces, more Bean usage, recurring items, and 1 year of history.</small></div>
        </section>
    </main>

    <section class="wrap pricing" id="plans">
        <div class="pricing-head">
            <h2>Simple tiers that scale with usage.</h2>
            <p>Bean usage limits are enforced by plan. Higher tiers include more AI capacity and more connected context.</p>
        </div>
        <div class="trial-note"><span>✓</span><div><strong>Beta note:</strong> while HeyBean is in beta, every user gets unlimited Bean use for free. These tiers show the planned post-beta structure.</div></div>

        <div class="plans">
            <article class="plan">
                <h3>Free</h3>
                <p class="for">For trying Bean or keeping one personal routine organized.</p>
                <div class="price"><span class="amount">$0</span><span class="period">/mo</span></div>
                <p class="trial">Start free</p>
                <ul class="features">
                    <li>2 workspaces</li>
                    <li>Basic tasks, reminders, and calendar</li>
                    <li>Basic Bean chat and voice</li>
                    <li>1 calendar connection</li>
                    <li>Push reminders</li>
                    <li>7-14 days of history</li>
                    <li>Low daily Bean cost cap</li>
                </ul>
                <a class="button secondary" href="/register?plan=free">Start free</a>
                <p class="fine">No paid trial needed for Free.</p>
            </article>

            <article class="plan popular">
                <span class="badge">Most popular</span>
                <h3>Premium</h3>
                <p class="for">For everyday household planning, recurring logistics, and richer daily context.</p>
                <div class="price"><span class="amount">$10</span><span class="period">/mo</span></div>
                <p class="trial">7-day free trial, then billed on day 8</p>
                <ul class="features">
                    <li>5 workspaces</li>
                    <li>Higher Bean usage</li>
                    <li>Push and email reminders</li>
                    <li>Recurring tasks and reminders</li>
                    <li>Multiple calendar connections</li>
                    <li>1 year of history</li>
                    <li>Best fit for most families and personal power users</li>
                </ul>
                <a class="button" href="/register?plan=premium">Start Premium trial</a>
                <p class="fine">Cancel before day 8 to avoid being billed.</p>
            </article>

            <article class="plan">
                <h3>Pro</h3>
                <p class="for">For heavy Bean users, multi-workspace operators, and advanced background work.</p>
                <div class="price"><span class="amount">$25</span><span class="period">/mo</span></div>
                <p class="trial">7-day free trial, then billed on day 8</p>
                <ul class="features">
                    <li>Unlimited workspaces</li>
                    <li>Highest Bean usage</li>
                    <li>Higher external tool budget</li>
                    <li>Unlimited connected accounts</li>
                    <li>Full memory and history</li>
                    <li>Priority background work</li>
                    <li>Priority support</li>
                </ul>
                <a class="button dark" href="/register?plan=pro">Start Pro trial</a>
                <p class="fine">Built for users who create real AI and automation cost.</p>
            </article>
        </div>

        <div class="compare">
            <div class="info">
                <b>Why usage limits matter</b>
                <p>Bean uses AI and external services in the background. Plan limits keep Free useful while making Premium and Pro sustainable for heavier daily use.</p>
            </div>
            <div class="info">
                <b>Billing note</b>
                <p>During beta, all users get unlimited use for free. Pricing intent is captured during early access so Stripe checkout and subscription enforcement can be wired next using the same Free, Premium, and Pro plan keys.</p>
            </div>
        </div>
    </section>

    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. AI executive assistance for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a></span></footer>
</body>
</html>
