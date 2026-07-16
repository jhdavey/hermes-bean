<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HeyBean — calendars, tasks, reminders, and notes</title>
    <meta name="description" content="HeyBean keeps calendars, tasks, reminders, notes, and shared workspaces together in one calm productivity app.">
    <link rel="icon" href="{{ asset('favicon.ico') }}"><link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @include('partials.public-postbridge-styles')
</head>
<body>
    <div class="public-beta-banner"><div class="public-beta-banner-inner">HeyBean is currently in Beta. <a href="/register">Sign up here</a> to create your beta account.</div></div>
    <header class="wrap nav">
        <a class="brand" href="/"><img src="{{ asset('images/bean-logo.png') }}" alt="HeyBean logo">HeyBean</a>
        <nav class="navlinks"><a href="#features">Features</a><a href="#reviews">Reviews</a><a href="/pricing">Pricing</a><a class="nav-login" href="/login">Login</a></nav>
        <details class="mobile-menu"><summary aria-label="Open menu">Menu</summary><div class="mobile-menu-panel"><a href="#features">Features</a><a href="#reviews">Reviews</a><a href="/pricing">Pricing</a><a href="/login">Login</a></div></details>
    </header>
    <main>
        <section class="wrap hero">
            <h1>Run your day with Bean</h1>
            <p class="hero-subhead">Easy calendar, task, reminder, note, and workspace management in one focused place.</p>
            <div class="hero-actions"><a class="button" href="/register">Try it for free</a><a class="button outline" href="#features">See features</a></div>
            <p class="proof">Used by <strong>{{ number_format($proofUserCount) }}</strong> busy households and operators</p>
        </section>
        <section class="section soft" id="features"><div class="wrap">
            <div class="section-head"><span class="section-kicker">ONE ORGANIZED DAY</span><h2>Everything important stays visible</h2><p>Plan time, keep commitments, write things down, and coordinate shared spaces without jumping between disconnected tools.</p></div>
            <div class="feature-grid">
                <article class="feature-card"><img src="{{ asset('images/bean-real-calendar-screen.png') }}" alt="HeyBean calendar"><h3>Keep every calendar moving.</h3><p>See connected and local events together, manage recurring schedules, and attach locations and notes.</p></article>
                <article class="feature-card"><img src="{{ asset('images/bean-real-home-screen.png') }}" alt="HeyBean daily overview"><h3>See the day you are running.</h3><p>Keep today’s events, overdue work, and upcoming commitments in a calm daily view.</p></article>
                <article class="feature-card"><img src="{{ asset('images/bean-real-reminders-screen.png') }}" alt="HeyBean reminders"><h3>Turn loose ends into managed work.</h3><p>Track tasks and reminders across personal, household, work, and project spaces.</p></article>
            </div>
        </div></section>
        <section class="section" id="reviews"><div class="wrap">
            <div class="section-head"><span class="section-kicker">BUILT FOR REAL LIFE</span><h2>HeyBean is loved by busy people who need fewer loose ends.</h2></div>
            <div class="reviews-grid">
                <article class="review-card"><div class="review-user"><img src="{{ asset('images/heybean-review-alex.svg') }}" alt=""><div><h3>Alex Rivera</h3><span>Operations lead</span></div></div><p>“My calendar and task list finally feel like parts of the same day.”</p></article>
                <article class="review-card"><div class="review-user"><img src="{{ asset('images/heybean-review-maya.svg') }}" alt=""><div><h3>Maya Chen</h3><span>Parent and founder</span></div></div><p>“Shared workspaces make the handoff between home and work much easier.”</p></article>
                <article class="review-card"><div class="review-user"><img src="{{ asset('images/heybean-review-sam.svg') }}" alt=""><div><h3>Sam Patel</h3><span>Independent consultant</span></div></div><p>“Recurring reminders and notes keep follow-through in one place.”</p></article>
            </div>
        </div></section>
        <section class="cta-band soft" id="early-access"><div class="wrap"><h2>Get Early Access</h2><p>Join the beta and help shape a calmer productivity app.</p><form class="signup-form" action="{{ route('early-access.store') }}" method="post">@csrf<input type="email" name="email" placeholder="you@example.com" required><button class="button" type="submit">Request access</button></form></div></section>
    </main>
    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. Productivity for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a></span></footer>
    @if(session('early_access_status'))<div class="signup-modal" role="dialog" aria-modal="true"><div class="signup-modal-card"><h2>Thank you for signing up!</h2><p>We will send you an email as soon as we can share the app with you.</p><p>We look forward to your help with making Bean great.</p><a class="button" href="/">Sounds good</a></div></div>@endif
</body>
</html>
