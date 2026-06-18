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
            <span class="hero-icon" aria-label="Calendar">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18"/><rect x="3" y="4" width="18" height="18" rx="3"/></svg>
            </span>
            <span class="hero-icon" aria-label="Tasks">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 12.5 11 14.5 15.5 9.5"/><circle cx="12" cy="12" r="9"/></svg>
            </span>
            <span class="hero-icon" aria-label="Bean AI">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 13.9 9l5.6 1.9-5.6 1.9L12 18.5l-1.9-5.7-5.6-1.9L10.1 9 12 3.5Z"/><path d="M19 3v4M21 5h-4M5 17v3M6.5 18.5h-3"/></svg>
            </span>
            <span class="hero-icon" aria-label="Reminders">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            </span>
            <span class="hero-icon" aria-label="Voice">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/></svg>
            </span>
        </div>
        <h1>Run your day with Bean</h1>
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
            <span>Used by <strong>{{ number_format($proofUserCount ?? 1122) }}</strong> busy households and operators</span>
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
                    <div class="mock-card schedule-board product-window" aria-label="HeyBean scheduling preview">
                        <div class="mock-header">
                            <span>Scheduling</span>
                            <strong>Jun 18</strong>
                        </div>
                        <div class="calendar-grid" aria-hidden="true">
                            <span>Mon</span><span>Tue</span><span>Wed</span><span class="is-today">Thu</span><span>Fri</span>
                            <b>15</b><b>16</b><b>17</b><b class="is-today">18</b><b>19</b>
                        </div>
                        <div class="schedule-card"><span class="schedule-dot"></span><div><b>School drop-off</b><span>Today, 8:15 AM - household</span></div><strong>Ready</strong></div>
                        <div class="schedule-card"><span class="schedule-dot blue"></span><div><b>Team check-in</b><span>Today, 11:30 AM - work</span></div><strong>Synced</strong></div>
                        <div class="schedule-card"><span class="schedule-dot"></span><div><b>Dinner with Lauren</b><span>Friday at 7:00 PM</span></div><strong>Set</strong></div>
                    </div>
                </div>
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">SCHEDULING</span>
                    <h3>Keep every calendar moving.</h3>
                    <p>Bean helps you create, adjust, and review events across the calendars that shape your day, without making scheduling feel like a separate job.</p>
                    <ul class="feature-list">
                        <li>Daily, weekly, and monthly calendar views.</li>
                        <li>Shared workspaces for home, work, and recurring plans.</li>
                        <li>Calendar-aware suggestions before Bean changes anything important.</li>
                    </ul>
                </div>
            </div>

            <div class="feature-row">
                <div class="feature-media">
                    <div class="mock-card task-board product-window" aria-label="HeyBean task management preview">
                        <div class="mock-header">
                            <span>Tasks</span>
                            <strong>12 open</strong>
                        </div>
                        <div class="task-lanes">
                            <div>
                                <span class="lane-label">Today</span>
                                <div class="task-card"><b>Send field trip form</b><span>Household - due 3 PM</span></div>
                                <div class="task-card"><b>Review proposal edits</b><span>Work - waiting on Sam</span></div>
                            </div>
                            <div>
                                <span class="lane-label">Next</span>
                                <div class="task-card"><b>Renew parking pass</b><span>Reminder tomorrow</span></div>
                                <div class="task-card done"><b>Order birthday gift</b><span>Completed by Bean</span></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">TASK MANAGEMENT</span>
                    <h3>Turn loose ends into managed tasks.</h3>
                    <p>Capture what needs to happen, let Bean sort the next step, and keep task work connected to the people, dates, and reminders that make it real.</p>
                    <ul class="feature-list">
                        <li>Natural-language task capture from chat or voice.</li>
                        <li>Due dates, owners, and workspace context in one list.</li>
                        <li>Follow-up reminders when a task needs another nudge.</li>
                    </ul>
                </div>
            </div>

            <div class="feature-row reverse">
                <div class="feature-media">
                    <div class="mock-card daily-board product-window" aria-label="HeyBean daily control preview">
                        <div class="mock-header">
                            <span>Daily Control</span>
                            <strong>Ready</strong>
                        </div>
                        <div class="daily-summary">
                            <b>4 events</b>
                            <b>7 tasks</b>
                            <b>3 reminders</b>
                        </div>
                        <div class="schedule-card"><span class="schedule-dot"></span><div><b>Morning plan</b><span>Bean grouped the day by time and workspace</span></div><strong>Done</strong></div>
                        <div class="schedule-card"><span class="schedule-dot blue"></span><div><b>Needs approval</b><span>Move dentist reminder to Monday?</span></div><strong>Review</strong></div>
                        <div class="daily-note">One calm view for the calendar items, tasks, reminders, and approvals that need your attention.</div>
                    </div>
                </div>
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">DAILY CONTROL</span>
                    <h3>See the day Bean is helping you run.</h3>
                    <p>HeyBean gives you a simple control layer for what is scheduled, what is due, and what still needs a nudge across home, work, school, errands, and recurring routines.</p>
                    <ul class="feature-list">
                        <li>One place for calendar events, tasks, and reminders.</li>
                        <li>Approvals stay visible before sensitive actions happen.</li>
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
