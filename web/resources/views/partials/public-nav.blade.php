<header class="wrap nav">
    @unless($hideBeanPresence ?? false)
        @include('partials.public-bean-presence')
        <span class="public-bean-nav-spacer" aria-hidden="true"></span>
    @endunless
    <nav class="navlinks" aria-label="Primary navigation">
        <a href="/#how-it-works">How It Works</a>
        <a href="/#features">Features</a>
        <a href="/#plans">Pricing</a>
        <a class="nav-login" href="/login">Log In</a>
        <a class="nav-cta" href="/register?from=topbar_button">Try it for free</a>
    </nav>
    <details class="mobile-menu">
        <summary aria-label="Open menu"><span class="mobile-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span></summary>
        <div class="mobile-menu-panel">
            <a href="/">Home</a>
            <a href="/#how-it-works">How It Works</a>
            <a href="/#features">Features</a>
            <a href="/#plans">Pricing</a>
            <a href="/login">Log In</a>
            <a class="mobile-menu-cta" href="/register?from=mobile_menu">Try it for free</a>
        </div>
    </details>
</header>
@vite('resources/js/publicBean.js')
