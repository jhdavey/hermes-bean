<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HeyBean - AI executive assistant for real life</title>
    <meta name="description" content="HeyBean gives busy people an AI executive assistant named Bean for calendars, planning, tasks, reminders, approvals, and household coordination. First 100 early-access spots now open.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            color-scheme: light;
            --bg: #f7f8f3;
            --paper: #fffef9;
            --paper-strong: #ffffff;
            --ink: #172019;
            --muted: #5d6b62;
            --soft: #edf2e9;
            --green: #18a957;
            --green-dark: #0f743d;
            --gold: #f0c65a;
            --coral: #eb765e;
            --line: rgba(23, 32, 25, .13);
            --shadow: 0 24px 70px rgba(34, 55, 40, .16);
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(180deg, #fbfbf6 0%, var(--bg) 42%, #eef4ea 100%);
            color: var(--ink);
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
        }
        a { color: inherit; }
        .shell { width: min(1160px, calc(100% - 36px)); margin: 0 auto; }
        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 22px 0;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            font-weight: 900;
        }
        .brand img {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: white;
            padding: 7px;
            box-shadow: 0 10px 24px rgba(24, 169, 87, .16);
        }
        .brand strong {
            display: block;
            font-family: Sora, sans-serif;
            font-size: 1.08rem;
            letter-spacing: -.04em;
        }
        .brand span span {
            display: block;
            color: var(--muted);
            font-size: .74rem;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }
        .nav-links { display: flex; align-items: center; gap: 8px; }
        .nav-links a {
            border-radius: 10px;
            color: #334238;
            font-weight: 800;
            padding: 10px 13px;
            text-decoration: none;
            transition: background .18s ease, color .18s ease;
        }
        .nav-links a:hover { background: rgba(255, 255, 255, .72); color: var(--green-dark); }
        .nav-cta {
            background: var(--ink) !important;
            color: white !important;
            box-shadow: 0 12px 28px rgba(23, 32, 25, .16);
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1.02fr) minmax(320px, .98fr);
            gap: 56px;
            align-items: center;
            padding: 56px 0 36px;
        }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: 1px solid rgba(24, 169, 87, .24);
            background: rgba(255, 255, 255, .72);
            border-radius: 999px;
            color: var(--green-dark);
            font-size: .76rem;
            font-weight: 900;
            letter-spacing: .13em;
            padding: 8px 12px;
            text-transform: uppercase;
        }
        .eyebrow:before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: var(--green);
            box-shadow: 0 0 0 6px rgba(24, 169, 87, .12);
        }
        h1 {
            font-family: Sora, sans-serif;
            font-size: clamp(3rem, 6.7vw, 6.45rem);
            letter-spacing: -.082em;
            line-height: .91;
            margin: 18px 0 0;
            max-width: 870px;
        }
        .highlight {
            display: inline-block;
            position: relative;
        }
        .highlight:after {
            background: linear-gradient(90deg, rgba(240, 198, 90, .9), rgba(235, 118, 94, .5));
            border-radius: 999px;
            bottom: .04em;
            content: "";
            height: .13em;
            left: .02em;
            position: absolute;
            right: .02em;
            z-index: -1;
        }
        .lead {
            color: #405047;
            font-size: 1.17rem;
            line-height: 1.72;
            margin: 26px 0 0;
            max-width: 680px;
        }
        .hero-signup {
            align-items: center;
            background: rgba(255, 255, 255, .82);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: 0 18px 42px rgba(34, 55, 40, .11);
            display: grid;
            gap: 10px;
            grid-template-columns: minmax(0, 1fr) auto;
            margin-top: 30px;
            max-width: 650px;
            padding: 8px;
        }
        .hero-signup input {
            background: transparent;
            border: 0;
            color: var(--ink);
            font: inherit;
            min-height: 52px;
            outline: none;
            padding: 0 14px;
            width: 100%;
        }
        .hero-signup button,
        .button {
            align-items: center;
            border: 0;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            font-size: 1rem;
            font-weight: 950;
            justify-content: center;
            min-height: 52px;
            padding: 0 22px;
            text-decoration: none;
        }
        .hero-signup button,
        .button.primary {
            background: linear-gradient(135deg, var(--green), var(--green-dark));
            color: white;
            box-shadow: 0 14px 30px rgba(24, 169, 87, .24);
        }
        .button.secondary {
            background: white;
            border: 1px solid var(--line);
            color: var(--ink);
        }
        .hero-note {
            color: var(--muted);
            font-size: .95rem;
            font-weight: 700;
            margin: 12px 0 0;
        }
        .hero-status { max-width: 650px; margin-top: 12px; }
        .status {
            background: rgba(24, 169, 87, .12);
            border-radius: 8px;
            color: var(--green-dark);
            font-weight: 850;
            padding: 12px;
        }
        .errors {
            background: rgba(220, 38, 38, .1);
            border-radius: 8px;
            color: #9f1239;
            font-weight: 750;
            padding: 12px;
        }
        .proof {
            color: var(--muted);
            display: flex;
            flex-wrap: wrap;
            font-size: .94rem;
            font-weight: 800;
            gap: 12px;
            margin-top: 24px;
        }
        .proof span {
            align-items: center;
            background: rgba(255, 255, 255, .64);
            border: 1px solid rgba(23, 32, 25, .08);
            border-radius: 999px;
            display: inline-flex;
            gap: 8px;
            padding: 8px 11px;
        }
        .proof span:before {
            background: var(--green);
            border-radius: 999px;
            content: "";
            height: 7px;
            width: 7px;
        }

        .device-stage {
            min-height: 620px;
            position: relative;
        }
        .device-card {
            background: #fefdf7;
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: var(--shadow);
            padding: 18px;
            position: relative;
        }
        .device-card:before {
            background: linear-gradient(135deg, rgba(24, 169, 87, .14), rgba(240, 198, 90, .22));
            border-radius: 14px;
            content: "";
            inset: 18px;
            position: absolute;
        }
        .mockup-image,
        .phone {
            display: block;
            margin: 0 auto;
            position: relative;
            width: min(100%, 390px);
            z-index: 1;
        }
        .mockup-image {
            height: auto;
            filter: drop-shadow(0 28px 46px rgba(23, 32, 25, .2));
        }
        .phone {
            background: #121912;
            border: 1px solid rgba(255, 255, 255, .2);
            border-radius: 36px;
            box-shadow: 0 26px 64px rgba(23, 32, 25, .27);
            padding: 12px;
        }
        .phone-screen {
            background: linear-gradient(180deg, #f8fbf6, #edf5e8);
            border-radius: 28px;
            min-height: 570px;
            overflow: hidden;
            padding: 18px;
        }
        .phone-bar {
            align-items: center;
            display: flex;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .phone-bar b { font-family: Sora, sans-serif; letter-spacing: -.035em; }
        .avatar {
            background: var(--green);
            border-radius: 999px;
            color: white;
            display: grid;
            font-weight: 950;
            height: 36px;
            place-items: center;
            width: 36px;
        }
        .today-panel {
            background: white;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
        }
        .mini-label {
            color: var(--muted);
            font-size: .73rem;
            font-weight: 900;
            letter-spacing: .12em;
            text-transform: uppercase;
        }
        .today-panel h2 {
            font-family: Sora, sans-serif;
            font-size: 1.75rem;
            letter-spacing: -.06em;
            line-height: 1;
            margin: 8px 0 0;
        }
        .metric-row {
            display: grid;
            gap: 8px;
            grid-template-columns: repeat(3, 1fr);
            margin-top: 16px;
        }
        .metric {
            background: var(--soft);
            border-radius: 8px;
            padding: 10px;
        }
        .metric strong { display: block; font-size: 1.24rem; }
        .metric span { color: var(--muted); font-size: .76rem; font-weight: 800; }
        .chat {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }
        .bubble {
            border-radius: 12px;
            line-height: 1.42;
            padding: 12px;
        }
        .bubble.user {
            background: var(--ink);
            color: white;
            justify-self: end;
            max-width: 88%;
        }
        .bubble.bean {
            background: white;
            border: 1px solid var(--line);
            color: #314037;
        }
        .task-list {
            display: grid;
            gap: 9px;
            margin-top: 14px;
        }
        .task-row {
            align-items: center;
            background: white;
            border: 1px solid rgba(23, 32, 25, .1);
            border-radius: 10px;
            display: grid;
            gap: 10px;
            grid-template-columns: 20px 1fr auto;
            padding: 11px;
        }
        .check {
            border: 2px solid var(--green);
            border-radius: 999px;
            height: 18px;
            width: 18px;
        }
        .tag {
            background: rgba(235, 118, 94, .12);
            border-radius: 999px;
            color: #a54a36;
            font-size: .72rem;
            font-weight: 900;
            padding: 5px 8px;
        }
        .floating-note {
            background: var(--ink);
            border-radius: 12px;
            bottom: 28px;
            box-shadow: 0 20px 48px rgba(23, 32, 25, .22);
            color: white;
            left: -16px;
            max-width: 230px;
            padding: 14px;
            position: absolute;
            z-index: 2;
        }
        .floating-note strong { display: block; margin-bottom: 6px; }
        .floating-note span { color: #d8e6dc; font-size: .9rem; line-height: 1.4; }

        .section { padding: 52px 0; }
        .section-head {
            align-items: end;
            display: grid;
            gap: 24px;
            grid-template-columns: minmax(0, .9fr) minmax(260px, .55fr);
            margin-bottom: 24px;
        }
        .section-title {
            font-family: Sora, sans-serif;
            font-size: clamp(2.1rem, 4.5vw, 4rem);
            letter-spacing: -.066em;
            line-height: .98;
            margin: 0;
        }
        .section-copy {
            color: var(--muted);
            font-size: 1.06rem;
            line-height: 1.7;
            margin: 0;
        }
        .cards {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(3, 1fr);
        }
        .card {
            background: rgba(255, 255, 255, .78);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 14px 38px rgba(34, 55, 40, .07);
            padding: 22px;
        }
        .card .icon {
            align-items: center;
            background: var(--soft);
            border-radius: 8px;
            color: var(--green-dark);
            display: inline-flex;
            font-weight: 950;
            height: 38px;
            justify-content: center;
            margin-bottom: 16px;
            width: 38px;
        }
        .card h3 {
            font-family: Sora, sans-serif;
            letter-spacing: -.035em;
            margin: 0 0 10px;
        }
        .card p {
            color: var(--muted);
            line-height: 1.62;
            margin: 0;
        }
        .split-band {
            background: #172019;
            border-radius: 18px;
            color: white;
            display: grid;
            gap: 28px;
            grid-template-columns: minmax(0, .9fr) minmax(280px, .7fr);
            margin: 20px 0 18px;
            padding: 32px;
        }
        .split-band h2 {
            font-family: Sora, sans-serif;
            font-size: clamp(2rem, 4.2vw, 3.55rem);
            letter-spacing: -.065em;
            line-height: 1;
            margin: 0;
        }
        .split-band p {
            color: #dbe8de;
            font-size: 1.04rem;
            line-height: 1.7;
            margin: 18px 0 0;
        }
        .scenario-list {
            display: grid;
            gap: 10px;
            margin: 0;
            padding: 0;
        }
        .scenario-list li {
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 8px;
            color: #eef7ef;
            font-weight: 750;
            list-style: none;
            padding: 13px 14px;
        }
        .feature-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, 1fr);
        }
        .feature {
            align-items: start;
            background: white;
            border: 1px solid var(--line);
            border-radius: 8px;
            display: grid;
            gap: 14px;
            grid-template-columns: 10px 1fr;
            padding: 20px;
        }
        .feature:before {
            background: var(--green);
            border-radius: 999px;
            content: "";
            height: 10px;
            margin-top: 7px;
            width: 10px;
        }
        .feature:nth-child(2):before { background: var(--gold); }
        .feature:nth-child(3):before { background: var(--coral); }
        .feature:nth-child(4):before { background: #4f8fdb; }
        .feature:nth-child(5):before { background: #7c6fcb; }
        .feature:nth-child(6):before { background: var(--green-dark); }
        .feature h3 {
            font-family: Sora, sans-serif;
            letter-spacing: -.035em;
            margin: 0 0 8px;
        }
        .feature p {
            color: var(--muted);
            line-height: 1.62;
            margin: 0;
        }
        .cta-panel {
            align-items: center;
            background: linear-gradient(135deg, #fffef9 0%, #eef5e9 100%);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: var(--shadow);
            display: grid;
            gap: 24px;
            grid-template-columns: minmax(0, 1fr) auto;
            margin: 28px 0 74px;
            padding: 28px;
        }
        .cta-panel h2 {
            font-family: Sora, sans-serif;
            font-size: clamp(1.8rem, 3.2vw, 3rem);
            letter-spacing: -.055em;
            line-height: 1;
            margin: 0;
        }
        .cta-panel p {
            color: var(--muted);
            line-height: 1.65;
            margin: 12px 0 0;
            max-width: 720px;
        }
        .footer {
            color: var(--muted);
            font-weight: 750;
            padding: 24px 0 40px;
            text-align: center;
        }
        .sr-only {
            border: 0;
            clip: rect(0, 0, 0, 0);
            height: 1px;
            margin: -1px;
            overflow: hidden;
            padding: 0;
            position: absolute;
            white-space: nowrap;
            width: 1px;
        }

        @media (max-width: 920px) {
            .hero,
            .section-head,
            .split-band,
            .cta-panel {
                grid-template-columns: 1fr;
            }
            .hero { gap: 34px; padding-top: 30px; }
            .device-stage { min-height: auto; }
            .cards,
            .feature-grid { grid-template-columns: 1fr; }
            .floating-note {
                left: 14px;
                right: 14px;
                max-width: none;
            }
            .nav-links a:not(.nav-cta) { display: none; }
        }

        @media (max-width: 620px) {
            .shell { width: min(100% - 24px, 1160px); }
            h1 { font-size: clamp(3rem, 14vw, 4.8rem); }
            .hero-signup { grid-template-columns: 1fr; }
            .hero-signup button { width: 100%; }
            .proof span { width: 100%; }
            .phone { width: 100%; }
            .phone-screen { min-height: 540px; padding: 15px; }
            .metric-row { grid-template-columns: 1fr; }
            .split-band,
            .cta-panel { padding: 22px; }
        }
    </style>
</head>
<body>
    @php($heroMockup = public_path('images/iphone-app-mockup.png'))

    <header class="shell nav">
        <a class="brand" href="/" aria-label="HeyBean home">
            <img src="{{ asset('images/bean-logo-color.png') }}" alt="HeyBean logo">
            <span><strong>HeyBean</strong><span>AI executive assistant</span></span>
        </a>
        <nav class="nav-links" aria-label="Primary navigation">
            <a href="#how-it-works">How it works</a>
            <a href="#features">Features</a>
            <a href="#early-access" class="nav-cta">Get Early Access</a>
        </nav>
    </header>

    <main class="shell">
        <section class="hero">
            <div>
                <div class="eyebrow">First 100 early-access spots</div>
                <h1 aria-label="Meet Bean, the AI executive assistant for real life.">Meet Bean, the AI executive assistant for <span class="highlight">real life</span>.</h1>
                <p class="lead">HeyBean helps busy people and households stay organized by turning plain-language requests into calendar planning, task updates, reminders, approvals, and daily follow-through.</p>
                <form class="hero-signup" id="early-access" action="{{ route('early-access.store') }}" method="post">
                    @csrf
                    <label class="sr-only" for="email">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}" placeholder="you@example.com">
                    <button type="submit">Join the first 100</button>
                </form>
                <p class="hero-note">Private mobile app access is opening to the first 100 people who sign up.</p>
                @if (session('early_access_status'))
                    <div class="status hero-status" role="status">{{ session('early_access_status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="errors hero-status" role="alert">Please check your email address and try again.</div>
                @endif
                <div class="proof" aria-label="HeyBean trust markers">
                    <span>Calendar and planning</span>
                    <span>Tasks and reminders</span>
                    <span>Google Calendar sync</span>
                </div>
            </div>

            <div class="device-stage" aria-label="HeyBean mobile app preview">
                <div class="device-card">
                    @if (file_exists($heroMockup))
                        <img class="mockup-image" src="{{ asset('images/iphone-app-mockup.png') }}" alt="HeyBean iPhone app preview">
                    @else
                        <div class="phone" aria-label="HeyBean app fallback preview">
                            <div class="phone-screen">
                                <div class="phone-bar">
                                    <b>Today</b>
                                    <span class="avatar">B</span>
                                </div>
                                <div class="today-panel">
                                    <span class="mini-label">Bean brief</span>
                                    <h2>School pickup, launch review, and dinner are covered.</h2>
                                    <div class="metric-row">
                                        <div class="metric"><strong>4</strong><span>tasks</span></div>
                                        <div class="metric"><strong>3</strong><span>events</span></div>
                                        <div class="metric"><strong>2</strong><span>reminders</span></div>
                                    </div>
                                </div>
                                <div class="chat">
                                    <div class="bubble user">Move my focus block, remind Sam about cleats, and add groceries to the household list.</div>
                                    <div class="bubble bean">Done. I updated your calendar, added the reminder, and synced groceries to Household.</div>
                                </div>
                                <div class="task-list">
                                    <div class="task-row"><span class="check"></span><b>Pack soccer cleats</b><span class="tag">Family</span></div>
                                    <div class="task-row"><span class="check"></span><b>Review launch notes</b><span class="tag">Work</span></div>
                                    <div class="task-row"><span class="check"></span><b>Pick up prescriptions</b><span class="tag">Errand</span></div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
                <div class="floating-note">
                    <strong>Bean acts with guardrails.</strong>
                    <span>Low-risk updates can happen fast. Sensitive actions wait for approval.</span>
                </div>
            </div>
        </section>

        <section class="section" id="how-it-works">
            <div class="section-head">
                <h2 class="section-title">Tell Bean what needs to happen. Bean turns it into structure.</h2>
                <p class="section-copy">The app is built around the way organized people already think: calendars, lists, reminders, daily planning, and shared household context. Bean adds the logic layer so those systems stay current without constant tapping.</p>
            </div>
            <div class="cards">
                <article class="card">
                    <div class="icon">1</div>
                    <h3>Ask in normal language</h3>
                    <p>Message Bean like an assistant: plan the day, move an event, create a recurring reminder, or capture a task before it disappears.</p>
                </article>
                <article class="card">
                    <div class="icon">2</div>
                    <h3>Bean updates the right systems</h3>
                    <p>Calendar events, tasks, reminders, categories, activity history, and workspace context are created in the app so your plan is usable later.</p>
                </article>
                <article class="card">
                    <div class="icon">3</div>
                    <h3>You approve what matters</h3>
                    <p>Bean can move quickly on routine organization while approvals and blockers keep higher-risk actions visible before they proceed.</p>
                </article>
            </div>
        </section>

        <section class="split-band">
            <div>
                <h2>Built for people coordinating work, home, and everything in between.</h2>
                <p>HeyBean is for people who already use calendars and lists, but want an assistant that can reason across them. Keep your own day organized, then create household spaces so shared tasks, reminders, calendars, and context live in one place.</p>
            </div>
            <ul class="scenario-list">
                <li>Plan tomorrow around meetings, errands, school pickup, and focus time.</li>
                <li>Sync a grocery task or reminder from Personal into a household workspace.</li>
                <li>Connect Google Calendar so Bean works from the schedule you already use.</li>
                <li>Keep a Today view with the tasks, reminders, events, approvals, and blockers that need attention.</li>
            </ul>
        </section>

        <section class="section" id="features">
            <div class="section-head">
                <h2 class="section-title">Executive assistance, not a blank chat box.</h2>
                <p class="section-copy">Early access focuses on the capabilities that make Bean useful day to day: calendar planning first, then the tasks and reminders that keep work and home moving.</p>
            </div>
            <div class="feature-grid">
                <article class="feature">
                    <div>
                        <h3>Calendar planning</h3>
                        <p>Plan the day, create events, move blocks around, and tune the calendar hours that matter to your actual routine.</p>
                    </div>
                </article>
                <article class="feature">
                    <div>
                        <h3>Tasks that stay organized</h3>
                        <p>Capture personal, work, household, chore, and maintenance tasks with categories, due dates, completion states, and shared workspace context.</p>
                    </div>
                </article>
                <article class="feature">
                    <div>
                        <h3>Reminders with context</h3>
                        <p>Add standalone reminders or reminders tied to events, including timing, recurrence details, and follow-up notes Bean can understand later.</p>
                    </div>
                </article>
                <article class="feature">
                    <div>
                        <h3>Today command center</h3>
                        <p>See your current events, tasks, reminders, activity, approvals, blockers, and counts in one daily surface.</p>
                    </div>
                </article>
                <article class="feature">
                    <div>
                        <h3>Personal and household workspaces</h3>
                        <p>Use a private Personal space or create a household space with members, shared context, and selective item sync.</p>
                    </div>
                </article>
                <article class="feature">
                    <div>
                        <h3>Google Calendar connection</h3>
                        <p>Connect, sync, and choose which Google calendars belong in each workspace so Bean can work with your real schedule.</p>
                    </div>
                </article>
            </div>
        </section>

        <section class="cta-panel">
            <div>
                <h2>Early access is limited to the first 100 people.</h2>
                <p>We are inviting practical AI users first: busy professionals, parents, partners, and household operators who want Bean to become a dependable daily assistant.</p>
            </div>
            <a class="button primary" href="#early-access">Get Early Access</a>
        </section>
    </main>

    <footer class="shell footer">&copy; {{ date('Y') }} HeyBean. Built for people who want AI to do useful work.</footer>
</body>
</html>
