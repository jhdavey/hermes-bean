<header class="wrap nav">
    <div
        class="public-bean-presence"
        data-public-bean
        data-mode="disabled"
        data-csrf-token="{{ csrf_token() }}"
        data-conversation-token-url="{{ route('bean.landing.conversation-token') }}"
        data-message-url="{{ route('bean.landing.messages') }}"
        data-voice-event-url="{{ route('bean.landing.voice-events') }}"
        data-turnstile-site-key="{{ config('services.turnstile.site_key') }}"
    >
        <span class="public-bean-ring" aria-hidden="true"></span>
        <button class="public-bean-control" type="button" data-public-bean-toggle aria-pressed="false" aria-label="Enable landing page Bean">
            <span class="public-bean-icon"><img src="{{ asset('images/bean-logo.png') }}" alt="Bean"></span>
            <span class="public-bean-status" data-public-bean-status aria-live="polite">Tap to enable</span>
        </button>
        <span class="public-bean-turnstile" data-public-bean-turnstile hidden></span>
    </div>
    <nav class="navlinks" aria-label="Primary navigation">
        <a href="/#how-it-works">How It Works</a>
        <a href="/#features">Features</a>
        <a href="/pricing">Pricing</a>
        <a class="nav-login" href="/login">Log In</a>
        <a class="nav-cta" href="/register">Create Free Account</a>
    </nav>
    <details class="mobile-menu">
        <summary aria-label="Open menu"><span class="mobile-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span></summary>
        <div class="mobile-menu-panel">
            <a href="/">Home</a>
            <a href="/#how-it-works">How It Works</a>
            <a href="/#features">Features</a>
            <a href="/pricing">Pricing</a>
            <a href="/login">Log In</a>
            <a class="mobile-menu-cta" href="/register">Create Free Account</a>
        </div>
    </details>
</header>
@vite('resources/js/publicBean.js')
