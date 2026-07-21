<header class="wrap nav">
    <div
        class="public-bean-presence"
        data-public-bean
        data-mode="disabled"
        data-csrf-token="{{ csrf_token() }}"
        data-conversation-token-url="{{ route('bean.landing.conversation-token') }}"
        data-message-url="{{ route('bean.landing.messages') }}"
    >
        <span class="public-bean-ring" aria-hidden="true"></span>
        <button class="public-bean-control" type="button" data-public-bean-toggle aria-pressed="false" aria-label="Enable landing page Bean">
            <span class="public-bean-icon"><img src="{{ asset('images/bean-logo.png') }}" alt="Bean"></span>
            <span class="public-bean-status" data-public-bean-status aria-live="polite">Tap to enable</span>
        </button>
    </div>
    <nav class="navlinks" aria-label="Primary navigation">
        <a href="/pricing">Pricing</a>
        <a href="/#reviews">Reviews</a>
        <a href="/#features">Features</a>
        <a class="nav-login" href="/login">Login</a>
    </nav>
    <details class="mobile-menu">
        <summary aria-label="Open menu"><span class="mobile-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span></summary>
        <div class="mobile-menu-panel">
            <a href="/">Home</a>
            <a href="/pricing">Pricing</a>
            <a href="/#reviews">Reviews</a>
            <a href="/#features">Features</a>
            <a href="/login">Login</a>
        </div>
    </details>
</header>
@vite('resources/js/publicBean.js')
