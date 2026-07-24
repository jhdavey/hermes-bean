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
        .tour-feature-stack{display:grid;gap:78px}.tour-section-head{max-width:760px;margin:0 auto 4px;text-align:center}.tour-section-head h2{margin:8px 0 0;font-size:clamp(32px,4vw,48px);line-height:1.08;letter-spacing:-.025em}.tour-section-head p{margin:14px auto 0;max-width:680px;color:var(--pb-muted);font-size:17px;line-height:1.65}.tour-feature-row{scroll-margin-top:130px}.tour-step-kicker{display:inline-flex;margin:0 0 12px;padding:7px 11px;border-radius:999px;background:rgba(123,201,140,.18);color:var(--pb-green-dark);font-size:12px;font-weight:850;letter-spacing:.08em;text-transform:uppercase}.tour-screenshot-card{position:relative;overflow:hidden;transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease;cursor:zoom-in}.tour-screenshot-card:after{content:"Tap tour step to zoom";position:absolute;right:14px;bottom:14px;padding:7px 10px;border-radius:999px;background:rgba(23,58,40,.86);color:#fff;font-size:12px;font-weight:800;opacity:.86}.tour-screenshot{transition:transform .24s ease;transform-origin:center top}.tour-screenshot.portrait{max-height:620px;object-fit:cover;object-position:top center}.tour-screenshot-card.wide .tour-screenshot{object-fit:cover}.tour-feature-row.public-bean-guided-highlight .tour-screenshot-card,.tour-feature-row:focus-within .tour-screenshot-card,.tour-feature-row:hover .tour-screenshot-card{transform:translateY(-4px) scale(1.018);box-shadow:0 24px 55px rgba(23,58,40,.16);border-color:rgba(82,168,105,.45)}.tour-feature-row.public-bean-guided-highlight .tour-screenshot,.tour-feature-row:focus-within .tour-screenshot,.tour-feature-row:hover .tour-screenshot{transform:scale(1.045)}.tour-image-zoom{position:fixed;inset:0;z-index:140;display:flex;align-items:center;justify-content:center;padding:40px;background:rgba(13,23,18,.78);backdrop-filter:blur(10px)}.tour-image-zoom img{display:block;max-width:min(1120px,92vw);max-height:86vh;border-radius:28px;border:1px solid rgba(255,255,255,.32);box-shadow:0 28px 80px rgba(0,0,0,.42);object-fit:contain;background:#fff}.tour-image-zoom-close{position:absolute;top:22px;right:24px;width:46px;height:46px;border:1px solid rgba(255,255,255,.38);border-radius:999px;background:rgba(255,255,255,.16);color:#fff;font-size:32px;line-height:1;cursor:pointer}.tour-image-zoom-close:focus-visible{outline:3px solid var(--pb-green);outline-offset:3px}@media(max-width:920px){.tour-feature-stack{gap:56px}.tour-screenshot.portrait{max-height:540px}.tour-screenshot-card:after{content:"";display:none}}@media(max-width:620px){.tour-image-zoom{padding:18px}.tour-image-zoom img{max-width:96vw;max-height:82vh;border-radius:20px}.tour-image-zoom-close{top:12px;right:12px}}
    </style>
</head>
<body>
    @include('partials.public-beta-banner')
    @include('partials.public-nav', ['hideBeanPresence' => true])

    <main class="wrap hero">
        @include('partials.public-bean-presence', [
            'class' => 'public-bean-presence-hero',
            'status' => 'Tap to wake up',
            'help' => 'Volume on · allow mic',
            'ariaLabel' => 'Wake up Bean',
        ])
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
        <h1>Hi! I'm Bean. Your new assistant!</h1>
        <p class="hero-subhead">Bean is here to help you stay organized and on top of things like your calendar, tasks, reminders and more. Across personal and shared workspaces, Bean is by your side, 24/7.</p>
        <div class="hero-actions">
            <a class="button" href="/register?from=hero_cta">Try it for free <span aria-hidden="true">→</span></a>
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
        <div class="wrap tour-feature-stack">
            <div class="section-head tour-section-head">
                <span class="eyebrow">Quick interactive tour</span>
                <h2>Three things Bean is built to show you fast.</h2>
                <p>Bean can move the page to each stop while it talks, so the tour stays short and visual instead of turning into a long feature list.</p>
            </div>

            <div class="feature-row reverse tour-feature-row" id="tour-command-center">
                <div class="feature-media">
                    <figure class="mock-card landing-screenshot-card tour-screenshot-card" aria-label="HeyBean command center with Bean screenshot">
                        <img class="landing-screenshot tour-screenshot portrait" src="{{ asset('images/heybean-tour-command-center-bean.png') }}?v={{ filemtime(public_path('images/heybean-tour-command-center-bean.png')) }}" width="1320" height="2868" loading="lazy" alt="HeyBean command center in the app with Bean visible in the bottom action button">
                    </figure>
                </div>
                <div class="feature-copy">
                    <span class="tour-step-kicker">1 · Command center + Bean</span>
                    <h3>Open your day with Bean already in reach.</h3>
                    <p>The command center pulls the day’s calendar, tasks, reminders, and follow-through into one place, with Bean available from the same view when you need help deciding what to do next.</p>
                    <ul class="feature-list">
                        <li>Daily command center for what needs attention now.</li>
                        <li>Bean stays one tap away for voice or chat follow-up.</li>
                        <li>Work, home, and personal commitments appear together without losing context.</li>
                    </ul>
                </div>
            </div>

            <div class="feature-row tour-feature-row" id="tour-calendar-tasks">
                <div class="feature-media">
                    <figure class="mock-card landing-screenshot-card tour-screenshot-card wide" aria-label="HeyBean calendar and tasks screenshots">
                        <img class="landing-screenshot tour-screenshot" src="{{ asset('images/heybean-tour-calendar-tasks.png') }}?v={{ filemtime(public_path('images/heybean-tour-calendar-tasks.png')) }}" width="876" height="754" loading="lazy" alt="HeyBean calendar and tasks views from the demo account">
                    </figure>
                </div>
                <div class="feature-copy">
                    <span class="tour-step-kicker">2 · Calendar + tasks</span>
                    <h3>Plan the calendar and the work in the same system.</h3>
                    <p>Calendar blocks and tasks can live side by side, so Bean can help you see what is scheduled, what is overdue, and what needs to move before your week gets overloaded.</p>
                    <ul class="feature-list">
                        <li>Calendar views for daily and monthly planning.</li>
                        <li>Task lists with due dates, recurrence, and critical markers.</li>
                        <li>Plain-language capture that becomes structured follow-through.</li>
                    </ul>
                </div>
            </div>

            <div class="feature-row reverse tour-feature-row" id="tour-customization">
                <div class="feature-media">
                    <figure class="mock-card landing-screenshot-card tour-screenshot-card wide theme-array" aria-label="HeyBean dashboard customization and themes screenshot">
                        <img class="landing-screenshot tour-screenshot" src="{{ asset('images/heybean-tour-customization-themes.png') }}?v={{ filemtime(public_path('images/heybean-tour-customization-themes.png')) }}" width="1280" height="900" loading="lazy" alt="HeyBean settings showing accent colors and light, auto, and dark theme options">
                    </figure>
                </div>
                <div class="feature-copy">
                    <span class="tour-step-kicker">3 · Modular dashboard + themes</span>
                    <h3>Make the dashboard match how you actually work.</h3>
                    <p>HeyBean’s dashboard is modular: calendar, tasks, reminders, notes, workspaces, and settings are separate views you can move between quickly, with custom accent colors and light, auto, or dark mode theming.</p>
                    <ul class="feature-list">
                        <li>Modular views for calendar, tasks, reminders, notes, and settings.</li>
                        <li>Custom accent colors for the app chrome and controls.</li>
                        <li>Light, automatic, and dark modes — shown here in dark mode.</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="section clarity-section">
        <div class="wrap clarity-grid">
            <article class="clarity-card">
                <h2>More than a place to store another list.</h2>
                <p>A traditional task manager waits for you to enter, organize, and maintain everything. Bean helps turn what you say into structured follow-through—so using the system does not become another responsibility.</p>
                <strong>You can still review and edit everything. You just do not have to organize every detail manually.</strong>
            </article>
            <article class="clarity-card trust-card">
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

    <section class="cta-band final-cta">
        <div class="wrap">
            <h2>Let Bean take the next few things off your mind.</h2>
            <p class="hero-subhead">Start with one request. Bean will help you turn it into an organized plan for what happens next.</p>
            <div class="hero-actions">
                <a class="button" href="/register?from=final_cta">Try it for free <span aria-hidden="true">→</span></a>
            </div>
            <p class="hero-microcopy">24 of 100 spots left · 7-day free trial after plan selection</p>
        </div>
    </section>

    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. AI executive assistance for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a> · <a href="/#plans">Pricing</a> · <a href="/login">Log In</a></span></footer>
    @include('partials.public-pricing-script')
</body>
</html>
