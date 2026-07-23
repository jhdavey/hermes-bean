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
        <button class="public-bean-control" type="button" data-public-bean-toggle aria-pressed="false" aria-label="Talk with landing page Bean">
            <span class="public-bean-icon"><img src="{{ asset('images/bean-logo.png') }}" alt="Bean"></span>
            <span class="public-bean-status" data-public-bean-status aria-live="polite">Tap to talk</span>
        </button>
        <button class="public-bean-cue" type="button" data-public-bean-cue aria-label="Talk with Bean">
            <span>Hey! I'm over here!</span>
            <svg viewBox="0 0 96 68" focusable="false" aria-hidden="true">
                <path class="public-bean-cue-arrow" d="M86 58 C58 50 34 30 11 8"></path>
                <path class="public-bean-cue-head" d="M13 8 L28 8 M13 8 L16 23"></path>
            </svg>
        </button>
        <span class="public-bean-help" data-public-bean-help>Turn your volume on, then allow microphone access.</span>
        <span class="public-bean-turnstile" data-public-bean-turnstile hidden></span>
    </div>
    <nav class="navlinks" aria-label="Primary navigation">
        <a href="/#how-it-works">How It Works</a>
        <a href="/#features">Features</a>
        <a href="/#plans">Pricing</a>
        <a class="nav-login" href="/login">Log In</a>
        <a class="nav-cta" href="/#early-access">Request Early Access</a>
    </nav>
    <details class="mobile-menu">
        <summary aria-label="Open menu"><span class="mobile-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span></summary>
        <div class="mobile-menu-panel">
            <a href="/">Home</a>
            <a href="/#how-it-works">How It Works</a>
            <a href="/#features">Features</a>
            <a href="/#plans">Pricing</a>
            <a href="/login">Log In</a>
            <a class="mobile-menu-cta" href="/#early-access">Request Early Access</a>
        </div>
    </details>
</header>
@vite('resources/js/publicBean.js')
