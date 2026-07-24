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
    <a class="nav-login" href="/login">Login</a>
    <details class="mobile-menu">
        <summary aria-label="Open menu"><span class="mobile-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span></summary>
        <div class="mobile-menu-panel">
            <a href="/">Home</a>
            <a href="/#how-it-works">How It Works</a>
            <a href="/#features">Features</a>
            <a href="/#plans">Pricing</a>
            <a href="/login">Login</a>
        </div>
    </details>
</header>
@vite('resources/js/publicBean.js')
