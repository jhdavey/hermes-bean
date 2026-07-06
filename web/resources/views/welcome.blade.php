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
    <style>
        .bean-demo-proof-shell{position:absolute;inset:0;z-index:3;pointer-events:none;color:#17231b;font-family:inherit}
        .bean-proof-screen{position:absolute;inset:0;border-radius:0;opacity:0;transform:translateX(18px);overflow:hidden;background:#edf8ec;box-shadow:none}
        .bean-proof-screen img{display:block;width:100%;height:100%;object-fit:cover;object-position:top center}
        .bean-demo-tap{position:absolute;z-index:8;width:42px;height:42px;border-radius:999px;background:rgba(22,163,74,.24);border:2px solid rgba(22,163,74,.95);box-shadow:0 0 0 0 rgba(22,163,74,.20);opacity:0;transform:translate(-50%,-50%) scale(.64)}
        .bean-demo-tap:after{content:"";position:absolute;inset:10px;border-radius:inherit;background:#16a34a}
        .bean-demo-tap.calendar{left:11.2%;top:94.1%}
        .bean-demo-tap.reminders{left:70.2%;top:94.1%}
        .bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-soft-mask{animation:beanSoftMaskProofCycle 16s linear infinite both}.bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-input:before{animation:beanInputPlaceholderProofCycle 16s linear infinite both}.bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-input-text{animation:beanInputTextProofCycle 16s linear infinite both}.bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-user{animation:beanCardUserProofCycle 16s ease infinite both}.bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-progress{animation:beanCardProgressProofCycle 16s ease infinite both}.bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-result{animation:beanCardResultProofCycle 16s ease infinite both}.bean-proof-screen.calendar{animation:beanCalendarScreenCycle 16s ease infinite both}.bean-proof-screen.reminders{animation:beanReminderScreenCycle 16s ease infinite both}.bean-demo-tap.calendar{animation:beanTapCalendarCycle 16s ease infinite both}.bean-demo-tap.reminders{animation:beanTapReminderCycle 16s ease infinite both}@keyframes beanSoftMaskProofCycle{0%,67.8%,100%{opacity:1}68.6%,96%{opacity:0}}@keyframes beanInputPlaceholderProofCycle{0%,3%,33%,100%{opacity:1}4%,32%{opacity:0}}@keyframes beanInputTextProofCycle{0%,4%{opacity:0;clip-path:inset(0 100% 0 0)}5%{opacity:1;clip-path:inset(0 100% 0 0)}24%,31%{opacity:1;clip-path:inset(0 0 0 0)}32%,100%{opacity:0;clip-path:inset(0 0 0 0)}}@keyframes beanCardUserProofCycle{0%,31%,96%,100%{opacity:0;transform:translateY(8px)}34%,62%{opacity:1;transform:translateY(0)}}@keyframes beanCardProgressProofCycle{0%,39%,52%,100%{opacity:0;transform:translateY(8px)}42%,49%{opacity:1;transform:translateY(0)}}@keyframes beanCardResultProofCycle{0%,50%,67%,100%{opacity:0;transform:translateY(8px)}53%,64%{opacity:1;transform:translateY(0)}}@keyframes beanTapCalendarCycle{0%,61.5%,68%,100%{opacity:0;transform:translate(-50%,-50%) scale(.64);box-shadow:0 0 0 0 rgba(22,163,74,.22)}62.5%,66%{opacity:1;transform:translate(-50%,-50%) scale(1);box-shadow:0 0 0 14px rgba(22,163,74,.08)}64%{opacity:.95;transform:translate(-50%,-50%) scale(.78);box-shadow:0 0 0 23px rgba(22,163,74,0)}}@keyframes beanCalendarScreenCycle{0%,66%,82%,100%{opacity:0;transform:translateX(18px)}68%,78.5%{opacity:1;transform:translateX(0)}}@keyframes beanTapReminderCycle{0%,77.5%,84%,100%{opacity:0;transform:translate(-50%,-50%) scale(.64);box-shadow:0 0 0 0 rgba(22,163,74,.22)}78.5%,82%{opacity:1;transform:translate(-50%,-50%) scale(1);box-shadow:0 0 0 14px rgba(22,163,74,.08)}80%{opacity:.95;transform:translate(-50%,-50%) scale(.78);box-shadow:0 0 0 23px rgba(22,163,74,0)}}@keyframes beanReminderScreenCycle{0%,82%,98%,100%{opacity:0;transform:translateX(18px)}84%,95%{opacity:1;transform:translateX(0)}}@media(prefers-reduced-motion:reduce){.bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-soft-mask,.bean-proof-screen.reminders{opacity:1!important;transform:none!important}.bean-proof-screen.calendar,.bean-demo-tap{display:none!important}}
    </style>
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
        <p class="hero-subhead">Easy calendar, task, and reminder management with Bean, your AI assistant for the real-life details that keep slipping between apps.</p>
        <div class="hero-actions">
            <a class="button" href="#early-access">Try it for free <span aria-hidden="true">-></span></a>
        </div>
        <div class="agent-pills" aria-label="HeyBean highlights">
            <a class="button ghost" href="#features">Capture once</a>
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
                                <div class="bean-demo-proof-shell" aria-hidden="true">
                                    <div class="bean-proof-screen calendar">
                                        <img src="{{ asset('images/bean-real-calendar-screen.png') }}?v={{ filemtime(public_path('images/bean-real-calendar-screen.png')) }}" width="1320" height="2868" alt="">
                                    </div>
                                    <div class="bean-proof-screen reminders">
                                        <img src="{{ asset('images/bean-real-reminders-screen.png') }}?v={{ filemtime(public_path('images/bean-real-reminders-screen.png')) }}" width="1320" height="2868" alt="">
                                    </div>
                                    <span class="bean-demo-tap calendar" aria-label="Calendar navigation tap"></span>
                                    <span class="bean-demo-tap reminders" aria-label="Reminders navigation tap"></span>
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
                        <li>Fast capture before small details slip away.</li>
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
                    <p>"I use Bean to turn quick thoughts into real tasks and events. It saves me from reopening my calendar every time plans change."</p>
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

    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. AI executive assistance for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a></span></footer>
</body>
</html>
