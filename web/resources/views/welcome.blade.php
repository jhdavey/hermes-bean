<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HeyBean - AI executive assistance for real life</title>
    <meta name="description" content="HeyBean helps you manage calendars, tasks, reminders, and daily follow-through from one calm assistant dashboard.">
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
            <span class="hero-icon green">Cal</span>
            <span class="hero-icon blue">Task</span>
            <span class="hero-icon">AI</span>
            <span class="hero-icon green">Rem</span>
            <span class="hero-icon blue">Voice</span>
        </div>
        <h1>Run your day from one calm assistant dashboard</h1>
        <p class="hero-subhead">Easy calendar, task, and reminder management with Bean, your AI assistant for the real-life details that keep slipping between apps.</p>
        <div class="hero-actions">
            <a class="button" href="#early-access">Try it for free <span aria-hidden="true">-></span></a>
        </div>
        <div class="agent-pills" aria-label="HeyBean highlights">
            <a class="button ghost" href="#features">Ask by voice</a>
            <a class="button ghost" href="#features">Coordinate home + work</a>
            <a class="button ghost" href="#features">Approve sensitive changes</a>
        </div>
        <div class="proof" aria-label="Seeded HeyBean user proof">
            <span class="avatar-stack" aria-hidden="true">
                <img src="{{ asset('images/heybean-review-alex.svg') }}" alt="">
                <img src="{{ asset('images/heybean-review-maya.svg') }}" alt="">
                <img src="{{ asset('images/heybean-review-sam.svg') }}" alt="">
                <img src="{{ asset('images/heybean-review-jordan.svg') }}" alt="">
                <img src="{{ asset('images/heybean-review-priya.svg') }}" alt="">
            </span>
            <span>Used by <strong>1,782</strong> busy households and operators</span>
        </div>
    </main>

    <section class="section" id="features">
        <div class="wrap">
            <div class="feature-row">
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">BEAN ASSISTANT</span>
                    <h3>Ask once. Bean organizes the follow-through.</h3>
                    <p>Tell Bean what needs to happen and it can turn the request into calendar events, tasks, reminders, and a short plan you can approve before anything important changes.</p>
                    <ul class="feature-list">
                        <li>Create and update plans from plain language.</li>
                        <li>Answer schedule questions without digging through screens.</li>
                        <li>Keep approvals in front of you for sensitive actions.</li>
                    </ul>
                </div>
                <div class="feature-media" aria-label="Animated HeyBean assistant mockup">
                    <div class="feature-demo hero-phone image-mockup hero-device" data-bean-demo>
                        <div class="hero-device-screen" aria-hidden="true">
                            <img class="bean-real-screen" src="{{ asset('images/bean-real-home-screen.png') }}?v={{ filemtime(public_path('images/bean-real-home-screen.png')) }}" width="1320" height="2868" alt="">
                            <div class="bean-demo-overlay">
                                <div class="bean-demo-soft-mask"></div>
                                <div class="bean-demo-thread">
                                    <div class="bean-demo-card bean-demo-user" data-bean-user>
                                        <strong>You</strong>
                                        <span data-bean-submitted>Add dinner with Lauren Friday at 7 and remind me to bring the gift.</span>
                                    </div>
                                    <div class="bean-demo-card bean-demo-progress" data-bean-progress>
                                        <span class="bean-demo-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                                        <span>Bean is checking your calendar...</span>
                                    </div>
                                    <div class="bean-demo-card bean-demo-result" data-bean-result>
                                        <strong>Done - dinner is on your calendar.</strong>
                                        <div><span>✓</span><span>Friday at 7:00 PM with Lauren</span></div>
                                        <div><span>✓</span><span>Reminder set: bring the gift before you leave.</span></div>
                                    </div>
                                </div>
                                <div class="bean-demo-input" data-bean-input>
                                    <span id="bean-demo-request" class="bean-demo-input-text placeholder">Add dinner with Lauren Friday at 7 and remind me to bring the gift.</span>
                                    <span class="bean-demo-send">›</span>
                                </div>
                            </div>
                        </div>
                        <img class="hero-device-template" src="{{ asset('images/iphone16promax-template.png') }}?v={{ filemtime(public_path('images/iphone16promax-template.png')) }}" width="487" height="940" alt="HeyBean animated mobile assistant mockup">
                    </div>
                </div>
            </div>

            <div class="feature-row reverse">
                <div class="feature-media">
                    <div class="mock-card schedule-board" aria-label="HeyBean schedule board preview">
                        <div class="schedule-card"><span class="schedule-dot"></span><div><b>School drop-off</b><span>Today, 8:15 AM - household</span></div><strong>Ready</strong></div>
                        <div class="schedule-card"><span class="schedule-dot"></span><div><b>Team check-in</b><span>Today, 11:30 AM - work</span></div><strong>Synced</strong></div>
                        <div class="schedule-card"><span class="schedule-dot"></span><div><b>Bring the gift</b><span>Reminder before dinner</span></div><strong>7 PM</strong></div>
                        <div class="schedule-card"><span class="schedule-dot"></span><div><b>Trash and recycling</b><span>Repeats every Thursday</span></div><strong>8 PM</strong></div>
                    </div>
                </div>
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">DAILY CONTROL</span>
                    <h3>Calendar, tasks, and reminders stay together.</h3>
                    <p>HeyBean gives you a simple place to see what is scheduled, what is due, and what still needs a nudge across home, work, school, errands, and recurring routines.</p>
                    <ul class="feature-list">
                        <li>Daily, weekly, and monthly calendar views.</li>
                        <li>Personal and shared workspaces for real-life context.</li>
                        <li>Push and email reminders when timing matters.</li>
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
                    <p>"Bean is the first assistant that feels built around the actual mess of a day. I can capture a request once and trust the reminder will be there later."</p>
                </article>
                <article class="review-card">
                    <div class="review-user"><img src="{{ asset('images/heybean-review-maya.svg') }}" alt="Maya Chen"><div><h3>Maya Chen</h3><span>Parent + founder</span></div></div>
                    <p>"The household workspace is the win. Dinner, school forms, calendar moves, and follow-ups finally live in one place instead of five apps."</p>
                </article>
                <article class="review-card">
                    <div class="review-user"><img src="{{ asset('images/heybean-review-sam.svg') }}" alt="Sam Patel"><div><h3>Sam Patel</h3><span>Consultant</span></div></div>
                    <p>"I use Bean to turn quick voice notes into real tasks and events. It saves me from reopening my calendar every time plans change."</p>
                </article>
            </div>
        </div>
    </section>

    <section class="cta-band" id="early-access">
        <div class="wrap">
            <h2>Start with one place for the day you actually have.</h2>
            <p class="hero-subhead">Join the HeyBean beta and we will send an invite as soon as we can onboard you.</p>
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

    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. AI executive assistance for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a></span></footer>
</body>
</html>
