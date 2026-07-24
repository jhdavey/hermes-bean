<header class="wrap nav">
    <a class="brand" href="/" aria-label="Bean home">
        <img src="{{ asset('images/bean-logo.png') }}" alt="" aria-hidden="true">
        <span>Bean</span>
    </a>
    <nav class="navlinks" aria-label="Primary navigation">
        <a href="/#how-it-works">How It Works</a>
        <a href="/#features">Features</a>
        <a href="/#plans">Pricing</a>
    </nav>
    <div class="nav-actions">
        <button class="public-theme-toggle" type="button" data-public-theme-toggle aria-pressed="false" aria-label="Switch to dark mode">
            <span data-public-theme-toggle-icon aria-hidden="true">☾</span>
            <span data-public-theme-toggle-label>Dark</span>
        </button>
        <a class="nav-login" href="/login">Login</a>
    </div>
    <details class="mobile-menu">
        <summary aria-label="Open menu"><span class="mobile-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span></summary>
        <div class="mobile-menu-panel">
            <a href="/">Home</a>
            <a href="/#how-it-works">How It Works</a>
            <a href="/#features">Features</a>
            <a href="/#plans">Pricing</a>
            <button class="public-theme-toggle mobile" type="button" data-public-theme-toggle aria-pressed="false" aria-label="Switch to dark mode">
                <span data-public-theme-toggle-icon aria-hidden="true">☾</span>
                <span data-public-theme-toggle-label>Dark</span>
            </button>
            <a href="/login">Login</a>
        </div>
    </details>
</header>
@vite('resources/js/publicBean.js')
