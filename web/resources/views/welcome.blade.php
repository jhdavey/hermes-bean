<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HeyBean – AI Executive Assistant for Work and Life</title>
    <meta name="description" content="HeyBean helps busy professionals and parents organize calendars, tasks, reminders, and everyday follow-through across work and home.">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site-manifest.json') }}">
    <meta name="theme-color" content="#7bc98c">
    @include('partials.public-postbridge-styles')
    @include('partials.public-pricing-styles')
    <style>
        .bean-demo-proof-shell{position:absolute;inset:0;z-index:3;pointer-events:none;color:#17231b;font-family:inherit}
        .bean-proof-screen{position:absolute;inset:0;border-radius:0;opacity:0;transform:translateX(18px);overflow:hidden;background:#edf8ec;box-shadow:none}
        .bean-proof-screen img{display:block;width:100%;height:100%;object-fit:cover;object-position:top center}
        .bean-demo-tap{position:absolute;z-index:8;width:42px;height:42px;border-radius:999px;background:rgba(22,163,74,.24);border:2px solid rgba(22,163,74,.95);box-shadow:0 0 0 0 rgba(22,163,74,.20);opacity:0;transform:translate(-50%,-50%) scale(.64)}
        .bean-demo-tap:after{content:"";position:absolute;inset:10px;border-radius:inherit;background:#16a34a}
        .bean-demo-tap.calendar{left:11.2%;top:94.1%}
        .bean-demo-tap.reminders{left:70.2%;top:94.1%}
        .bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-soft-mask{animation:beanSoftMaskProofCycle 16s linear infinite both}.bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-input:before{animation:beanInputPlaceholderProofCycle 16s linear infinite both}.bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-input-text{animation:beanInputTextProofCycle 16s linear infinite both}.bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-user{animation:beanCardUserProofCycle 16s ease infinite both}.bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-progress{animation:beanCardProgressProofCycle 16s ease infinite both}.bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-result{animation:beanCardResultProofCycle 16s ease infinite both}.bean-proof-screen.calendar{animation:beanCalendarScreenCycle 16s ease infinite both}.bean-proof-screen.reminders{animation:beanReminderScreenCycle 16s ease infinite both}.bean-demo-tap.calendar{animation:beanTapCalendarCycle 16s ease infinite both}.bean-demo-tap.reminders{animation:beanTapReminderCycle 16s ease infinite both}@keyframes beanSoftMaskProofCycle{0%,67.8%,100%{opacity:1}68.6%,96%{opacity:0}}@keyframes beanInputPlaceholderProofCycle{0%,3%,33%,100%{opacity:1}4%,32%{opacity:0}}@keyframes beanInputTextProofCycle{0%,4%{opacity:0;clip-path:inset(0 100% 0 0)}5%{opacity:1;clip-path:inset(0 100% 0 0)}24%,31%{opacity:1;clip-path:inset(0 0 0 0)}32%,100%{opacity:0;clip-path:inset(0 0 0 0)}}@keyframes beanCardUserProofCycle{0%,31%,96%,100%{opacity:0;transform:translateY(8px)}34%,62%{opacity:1;transform:translateY(0)}}@keyframes beanCardProgressProofCycle{0%,39%,52%,100%{opacity:0;transform:translateY(8px)}42%,49%{opacity:1;transform:translateY(0)}}@keyframes beanCardResultProofCycle{0%,50%,67%,100%{opacity:0;transform:translateY(8px)}53%,64%{opacity:1;transform:translateY(0)}}@keyframes beanTapCalendarCycle{0%,61.5%,68%,100%{opacity:0;transform:translate(-50%,-50%) scale(.64);box-shadow:0 0 0 0 rgba(22,163,74,.22)}62.5%,66%{opacity:1;transform:translate(-50%,-50%) scale(1);box-shadow:0 0 0 14px rgba(22,163,74,.08)}64%{opacity:.95;transform:translate(-50%,-50%) scale(.78);box-shadow:0 0 0 23px rgba(22,163,74,0)}}@keyframes beanCalendarScreenCycle{0%,66%,82%,100%{opacity:0;transform:translateX(18px)}68%,78.5%{opacity:1;transform:translateX(0)}}@keyframes beanTapReminderCycle{0%,77.5%,84%,100%{opacity:0;transform:translate(-50%,-50%) scale(.64);box-shadow:0 0 0 0 rgba(22,163,74,.22)}78.5%,82%{opacity:1;transform:translate(-50%,-50%) scale(1);box-shadow:0 0 0 14px rgba(22,163,74,.08)}80%{opacity:.95;transform:translate(-50%,-50%) scale(.78);box-shadow:0 0 0 23px rgba(22,163,74,0)}}@keyframes beanReminderScreenCycle{0%,82%,98%,100%{opacity:0;transform:translateX(18px)}84%,95%{opacity:1;transform:translateX(0)}}@media(prefers-reduced-motion:reduce){.bean-demo-proof-shell,.bean-demo-progress,.bean-demo-tap{display:none!important}.bean-demo-overlay:has(.bean-demo-proof-shell) .bean-demo-soft-mask,.bean-demo-user,.bean-demo-result{opacity:1!important;transform:none!important}}
    </style>
</head>
<body>
    @include('partials.public-beta-banner')
    @include('partials.public-nav')

    <main class="wrap hero">
        <div class="hero-icons" aria-label="HeyBean tools">
            <span class="hero-icon bean" aria-label="Bean">
                <img src="{{ asset('images/bean-logo.png') }}" alt="">
            </span>
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
        <span class="section-kicker hero-kicker">AI EXECUTIVE ASSISTANCE FOR REAL LIFE</span>
        <h1>Stop carrying every detail yourself.</h1>
        <p class="hero-subhead">HeyBean is the AI executive assistant for busy professionals and parents. Tell Bean what needs to happen, and it turns your requests into calendar events, tasks, reminders, and follow-ups across work and home.</p>
        <div class="hero-actions">
            <a class="button" href="#early-access">Request early access <span aria-hidden="true">→</span></a>
            <a class="button outline" href="#bean-demo">See Bean in action</a>
        </div>
        <p class="hero-microcopy">24 of 100 early-access spots left <span aria-hidden="true">·</span> 7-day free trial after plan selection</p>
        <div class="agent-pills" aria-label="HeyBean highlights">
            <a class="button ghost" href="#features">Capture it once</a>
            <a class="button ghost" href="#features">Coordinate work + home</a>
            <a class="button ghost" href="#features">Keep the follow-through moving</a>
        </div>
    </main>

    <section class="section soft problem-section" id="how-it-works">
        <div class="wrap problem-layout">
            <div class="problem-copy">
                <span class="section-kicker" style="text-align:left">THE MENTAL LOAD</span>
                <h2>Built for people balancing a career, a household, and everything between them.</h2>
                <p>Work deadlines, appointments, school forms, family plans, errands, and follow-ups rarely live in one place. Your calendar holds some of them. Your messages and notes hold others. And too many still depend on you remembering them.</p>
                <p class="problem-closing">Bean gives you one assistant for organizing what needs to happen next.</p>
            </div>
            <div class="life-load-grid" aria-label="Examples of work and personal responsibilities">
                <article class="life-load-card">
                    <span>Work</span>
                    <strong>Proposal deadline</strong>
                    <strong>Client follow-up</strong>
                    <strong>Focus time</strong>
                </article>
                <article class="life-load-card">
                    <span>Life</span>
                    <strong>Appointment</strong>
                    <strong>School form</strong>
                    <strong>Household errand</strong>
                </article>
            </div>
        </div>
    </section>

    <section class="section" id="bean-demo">
        <div class="wrap">
            <div class="feature-row">
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">BEAN ASSISTANT</span>
                    <h3>Ask once. Bean organizes what happens next.</h3>
                    <p>Bean doesn’t just answer questions. It turns what you tell it into an organized plan using your calendar, tasks, and reminders.</p>
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
                                        <span data-bean-submitted>Block 90 minutes Friday to finish the Acme proposal, add a follow-up with Jordan for Monday, and remind me about Ava’s school form tonight.</span>
                                    </div>
                                    <div class="bean-demo-card bean-demo-progress" data-bean-progress>
                                        <span class="bean-demo-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                                        <span>Bean is checking your calendar and organizing the details…</span>
                                    </div>
                                    <div class="bean-demo-card bean-demo-result" data-bean-result>
                                        <strong>Proposed plan</strong>
                                        <div><span>✓</span><span>Focus block: Acme proposal — Friday</span></div>
                                        <div><span>✓</span><span>Task: Follow up with Jordan — Monday</span></div>
                                        <div><span>✓</span><span>Reminder: Ava’s school form — Tonight</span></div>
                                        <span class="bean-demo-review">Review and approve</span>
                                    </div>
                                </div>
                                <div class="bean-demo-input" data-bean-input>
                                    <span id="bean-demo-request" class="bean-demo-input-text placeholder">Block 90 minutes Friday for the Acme proposal…</span>
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
        </div>
    </section>

    <section class="section soft" id="features">
        <div class="wrap">
            <div class="feature-row reverse">
                <div class="feature-media">
                    <figure class="mock-card landing-screenshot-card" aria-label="HeyBean task and reminder screenshot">
                        <img class="landing-screenshot" src="{{ asset('images/heybean-landing-task-management.png') }}?v={{ filemtime(public_path('images/heybean-landing-task-management.png')) }}" width="762" height="434" loading="lazy" alt="HeyBean task view showing work and personal commitments with dates and follow-ups">
                    </figure>
                </div>
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">CAPTURE</span>
                    <h3>Capture it before it slips away.</h3>
                    <p>Tell Bean what you need to remember while it is still on your mind. Bean can turn a quick request into an event, task, reminder, or simple plan without making you stop and organize everything yourself.</p>
                    <ul class="feature-list">
                        <li>Create events, tasks, and reminders from plain language.</li>
                        <li>Capture work and personal commitments in the same conversation.</li>
                        <li>Ask schedule questions without digging through screens.</li>
                    </ul>
                </div>
            </div>

            <div class="feature-row">
                <div class="feature-media">
                    <figure class="mock-card landing-screenshot-card" aria-label="HeyBean scheduling screenshot">
                        <img class="landing-screenshot" src="{{ asset('images/heybean-landing-scheduling.png') }}?v={{ filemtime(public_path('images/heybean-landing-scheduling.png')) }}" width="614" height="558" loading="lazy" alt="HeyBean calendar showing coordinated work and personal plans across the month">
                    </figure>
                </div>
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">COORDINATION</span>
                    <h3>Keep work and home moving together.</h3>
                    <p>Your responsibilities may belong to different parts of your life, but they still compete for the same time and attention. Bean helps you coordinate calendars, tasks, people, and reminders without treating household management like another job.</p>
                    <ul class="feature-list">
                        <li>Daily, weekly, and monthly calendar views.</li>
                        <li>Shared workspaces for work, home, and recurring plans.</li>
                        <li>Calendar-aware suggestions before important changes.</li>
                        <li>Dates, owners, and context connected to each task.</li>
                    </ul>
                </div>
            </div>

            <div class="feature-row reverse">
                <div class="feature-media">
                    <figure class="mock-card landing-screenshot-card" aria-label="HeyBean daily follow-through screenshot">
                        <img class="landing-screenshot" src="{{ asset('images/heybean-landing-daily-control.png') }}?v={{ filemtime(public_path('images/heybean-landing-daily-control.png')) }}" width="598" height="702" loading="lazy" alt="HeyBean daily view showing events, tasks, reminders, and notes">
                    </figure>
                </div>
                <div class="feature-copy">
                    <span class="section-kicker" style="text-align:left">DAILY FOLLOW-THROUGH</span>
                    <h3>Open Bean and know what needs your attention.</h3>
                    <p>See what is scheduled, what is due, and what still needs a nudge across work, family, appointments, errands, and recurring responsibilities.</p>
                    <ul class="feature-list">
                        <li>Events, tasks, and reminders in one daily view.</li>
                        <li>Follow-up reminders for unfinished commitments.</li>
                        <li>A clear view of what needs attention next.</li>
                        <li>Plans that can be adjusted as the day changes.</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="section clarity-section">
        <div class="wrap clarity-grid">
            <article class="clarity-card">
                <span class="section-kicker" style="text-align:left">LESS MANUAL ORGANIZING</span>
                <h2>More than a place to store another list.</h2>
                <p>A traditional task manager waits for you to enter, organize, and maintain everything. Bean helps turn what you say into structured follow-through—so using the system does not become another responsibility.</p>
                <strong>You can still review and edit everything. You just do not have to organize every detail manually.</strong>
            </article>
            <article class="clarity-card trust-card">
                <span class="section-kicker" style="text-align:left">YOU STAY IN CONTROL</span>
                <h2>Bean works for you—and you stay in control.</h2>
                <p>Review important changes before they happen, see what Bean has organized, and decide which information and services Bean can access.</p>
                <ul class="feature-list">
                    <li>Sensitive changes require your approval.</li>
                    <li>You can review and edit what Bean creates or updates.</li>
                    <li>You choose which calendars and workspaces Bean can use.</li>
                    <li>You can disconnect linked calendar services at any time.</li>
                </ul>
            </article>
        </div>
    </section>

    @include('partials.public-pricing-plans')

    @include('partials.public-early-access')

    <section class="cta-band final-cta">
        <div class="wrap">
            <h2>Let Bean take the next few things off your mind.</h2>
            <p class="hero-subhead">Start with one request. Bean will help you turn it into an organized plan for what happens next.</p>
            <div class="hero-actions">
                <a class="button" href="#early-access">Request early access <span aria-hidden="true">→</span></a>
            </div>
            <p class="hero-microcopy">24 of 100 spots left · 7-day free trial after plan selection</p>
        </div>
    </section>

    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. AI executive assistance for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a> · <a href="/#plans">Pricing</a> · <a href="/login">Log In</a></span></footer>
    @include('partials.public-pricing-script')
</body>
</html>
