<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HeyBean - productivity for real life</title>
    <meta name="description" content="HeyBean keeps calendars, tasks, reminders, notes, and shared workspaces together in one calm dashboard.">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site-manifest.json') }}">
    <meta name="theme-color" content="#7bc98c">
    @include('partials.public-postbridge-styles')
</head>
<body>
    @include('partials.public-beta-banner')
    @include('partials.public-nav')

    <main class="wrap hero">
        <div class="hero-icons" aria-label="HeyBean tools">
            <span class="hero-icon" aria-label="Calendar">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18"/><rect x="3" y="4" width="18" height="18" rx="3"/></svg>
            </span>
            <span class="hero-icon" aria-label="Tasks">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 12.5 11 14.5 15.5 9.5"/><circle cx="12" cy="12" r="9"/></svg>
            </span>
            <span class="hero-icon" aria-label="Notes">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h10a2 2 0 0 1 2 2v14H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/><path d="M9 4v16"/><path d="M12 8h4M12 12h4M12 16h3"/></svg>
            </span>
            <span class="hero-icon" aria-label="Reminders">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            </span>
        </div>
        <h1>Run your day with Bean</h1>
        <p class="hero-subhead">Easy calendar, task, reminder, note, and workspace management for the real-life details that keep slipping between apps.</p>
        <div class="hero-actions">
            <a class="button" href="#early-access">Try it for free <span aria-hidden="true">-></span></a>
        </div>
        <div class="highlight-pills" aria-label="HeyBean highlights">
            <a class="button ghost" href="#features">Capture once</a>
            <a class="button ghost" href="#features">Coordinate home + work</a>
            <a class="button ghost" href="#features">Keep every detail visible</a>
        </div>
        <div class="proof" aria-label="Seeded HeyBean user proof">
            <span class="avatar-stack" aria-hidden="true">
                <img src="{{ asset('images/heybean-review-alex.svg') }}" alt="">
                <img src="{{ asset('images/heybean-review-maya.svg') }}" alt="">
                <img src="{{ asset('images/heybean-review-sam.svg') }}" alt="">
                <img src="{{ asset('images/heybean-review-jordan.svg') }}" alt="">
                <img src="{{ asset('images/heybean-review-priya.svg') }}" alt="">
            </span>
            <span>Used by <strong>{{ number_format($proofUserCount ?? 1122) }}</strong> busy households and operators</span>
        </div>
    </main>

    <section class="section" id="features">
        <div class="wrap">
            <div class="feature-row">
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">COMMAND CENTER</span>
                    <h3>See today and what comes next.</h3>
                    <p>The command center keeps calendar events, tasks, and reminders together so the day stays clear without jumping between screens.</p>
                    <ul class="feature-list">
                        <li>See overdue and scheduled work in one agenda.</li>
                        <li>Open any item directly when it needs an update.</li>
                        <li>Look ahead to tomorrow and the following day.</li>
                    </ul>
                </div>
                <div class="feature-media" aria-label="HeyBean command center mockup">
                    <div class="feature-demo hero-phone image-mockup hero-device">
                        <div class="hero-device-screen" aria-hidden="true">
                            <img class="bean-real-screen" src="{{ asset('images/bean-real-calendar-screen.png') }}?v={{ filemtime(public_path('images/bean-real-calendar-screen.png')) }}" width="1320" height="2868" alt="">
                        </div>
                        <img class="hero-device-template" src="{{ asset('images/iphone16promax-template.png') }}?v={{ filemtime(public_path('images/iphone16promax-template.png')) }}" width="487" height="940" alt="HeyBean command center mobile mockup">
                    </div>
                </div>
            </div>

            <div class="feature-row reverse">
                <div class="feature-media">
                    <figure class="mock-card landing-screenshot-card" aria-label="HeyBean scheduling screenshot">
                        <img class="landing-screenshot" src="{{ asset('images/heybean-landing-scheduling.png') }}?v={{ filemtime(public_path('images/heybean-landing-scheduling.png')) }}" width="614" height="558" loading="lazy" alt="HeyBean scheduling screen showing a full calendar for Sarah's July schedule">
                    </figure>
                </div>
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">SCHEDULING</span>
                    <h3>Keep every calendar moving.</h3>
                    <p>Create, adjust, and review events across the calendars that shape your day, without making scheduling feel like a separate job.</p>
                    <ul class="feature-list">
                        <li>Daily, weekly, and monthly calendar views.</li>
                        <li>Shared workspaces for home, work, and recurring plans.</li>
                        <li>Clear event details before anything important changes.</li>
                    </ul>
                </div>
            </div>

            <div class="feature-row">
                <div class="feature-media">
                    <figure class="mock-card landing-screenshot-card" aria-label="HeyBean task management screenshot">
                        <img class="landing-screenshot" src="{{ asset('images/heybean-landing-task-management.png') }}?v={{ filemtime(public_path('images/heybean-landing-task-management.png')) }}" width="762" height="434" loading="lazy" alt="HeyBean task management screen showing Sarah's tasks and follow-ups">
                    </figure>
                </div>
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">TASK MANAGEMENT</span>
                    <h3>Turn loose ends into managed tasks.</h3>
                    <p>Capture what needs to happen and keep task work connected to the people, dates, and reminders that make it real.</p>
                    <ul class="feature-list">
                        <li>Fast capture before small details slip away.</li>
                        <li>Due dates, owners, and workspace context in one list.</li>
                        <li>Follow-up reminders when a task needs another nudge.</li>
                    </ul>
                </div>
            </div>

            <div class="feature-row reverse">
                <div class="feature-media">
                    <figure class="mock-card landing-screenshot-card" aria-label="HeyBean daily control screenshot">
                        <img class="landing-screenshot" src="{{ asset('images/heybean-landing-scheduling.png') }}?v={{ filemtime(public_path('images/heybean-landing-scheduling.png')) }}" width="614" height="558" loading="lazy" alt="HeyBean daily control agenda showing Sarah's upcoming events, tasks, and reminders">
                    </figure>
                </div>
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">DAILY CONTROL</span>
                    <h3>See the day you are running.</h3>
                    <p>HeyBean gives you a simple control layer for what is scheduled, what is due, and what still needs a nudge across home, work, school, errands, and recurring routines.</p>
                    <ul class="feature-list">
                        <li>One place for calendar events, tasks, and reminders.</li>
                        <li>Important details stay visible before changes are saved.</li>
                        <li>Daily planning that adapts as the day changes.</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="section soft" id="reviews">
        <div class="wrap">
            <div class="section-head">
                <span class="section-kicker">REVIEWS</span>
                <h2>HeyBean is loved by busy people who need fewer loose ends.</h2>
            </div>
            <div class="reviews-grid">
                <article class="review-card">
                    <div class="review-user"><img src="{{ asset('images/heybean-review-alex.svg') }}" alt="Alex Rivera"><div><h3>Alex Rivera</h3><span>Operations lead</span></div></div>
                    <p>"HeyBean feels built around the actual mess of a day. I can capture something once and trust the reminder will be there later."</p>
                </article>
                <article class="review-card">
                    <div class="review-user"><img src="{{ asset('images/heybean-review-maya.svg') }}" alt="Maya Chen"><div><h3>Maya Chen</h3><span>Parent + founder</span></div></div>
                    <p>"The household workspace is the win. Dinner, school forms, calendar moves, and follow-ups finally live in one place instead of five apps."</p>
                </article>
                <article class="review-card">
                    <div class="review-user"><img src="{{ asset('images/heybean-review-sam.svg') }}" alt="Sam Patel"><div><h3>Sam Patel</h3><span>Consultant</span></div></div>
                    <p>"I use HeyBean to turn quick thoughts into real tasks and events. It saves me from reopening my calendar every time plans change."</p>
                </article>
            </div>
        </div>
    </section>

    <section class="cta-band" id="early-access">
        <div class="wrap">
            <h2>Start with one place for the day you actually have.</h2>
            <p class="hero-subhead">Join the HeyBean beta for product updates, or create an account when you are ready to start.</p>
            <form class="hero-actions" method="POST" action="{{ route('early-access.store') }}">
                @csrf
                <input type="email" name="email" required placeholder="you@example.com" aria-label="Email address" style="min-height:56px;width:min(360px,100%);border:1px solid var(--pb-border);border-radius:9999px;padding:0 20px;font:inherit;color:var(--pb-ink);outline:none">
                <button class="button" type="submit">Get Early Access <span aria-hidden="true">-></span></button>
            </form>
            @if (session('early_access_status'))
                <p class="hero-subhead" style="font-size:15px"><strong>You are on the list - thank you.</strong></p>
            @endif
        </div>
    </section>

    @if (session('early_access_status'))
        <div class="signup-modal" role="dialog" aria-modal="true" aria-labelledby="signup-modal-title">
            <div class="signup-modal-card">
                <div class="signup-modal-icon" aria-hidden="true">✓</div>
                <h2 id="signup-modal-title">Thank you for signing up!</h2>
                <p>We will send you an email as soon as we can share the app with you. We look forward to your help with making Bean great.</p>
                <a class="button" href="/">Sounds good</a>
            </div>
        </div>
    @endif

    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. Productivity for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a></span></footer>
</body>
</html>
