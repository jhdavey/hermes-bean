<style>
    :root {
        color-scheme: light;
        --hb-ink: #2d3748;
        --hb-muted: #667085;
        --hb-bg0: #ffffff;
        --hb-bg1: #f8fbf6;
        --hb-bg2: #f1f7ee;
        --hb-surface: #ffffff;
        --hb-border: rgba(217, 221, 227, .92);
        --hb-accent: #7bc98c;
        --hb-accent-strong: #52a869;
        --hb-accent-ink: #173a28;
        --hb-shadow: 0 22px 70px rgba(30, 80, 45, .12);
    }

    * { box-sizing: border-box; }

    body {
        min-height: 100vh;
        margin: 0;
        display: grid;
        place-items: center;
        padding: 24px 16px;
        background:
            radial-gradient(circle at 12% -10%, rgba(123, 201, 140, .16), transparent 34%),
            linear-gradient(180deg, var(--hb-bg0) 0%, var(--hb-bg1) 52%, var(--hb-bg2) 100%);
        color: var(--hb-ink);
        font-family: "Plus Jakarta Sans", ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        line-height: 1.5;
    }

    .card {
        width: min(440px, 100%);
        padding: 28px;
        border: 1px solid var(--hb-border);
        border-radius: 24px;
        background: rgba(255, 255, 255, .94);
        box-shadow: var(--hb-shadow);
        backdrop-filter: blur(12px);
    }

    .card.center { text-align: center; }

    h1 {
        margin: 0 0 8px;
        color: var(--hb-ink);
        font-size: clamp(1.45rem, 4vw, 1.7rem);
        line-height: 1.12;
        font-weight: 850;
        letter-spacing: -.02em;
    }

    p { margin: 0; color: var(--hb-muted); }
    form { margin-top: 18px; display: grid; gap: 14px; }
    label { display: grid; gap: 6px; color: var(--hb-ink); font-weight: 800; }
    label span { color: var(--hb-muted); font-size: .78rem; font-weight: 750; }

    input {
        width: 100%;
        min-height: 48px;
        border: 1px solid var(--hb-border);
        border-radius: 999px;
        background: rgba(255, 255, 255, .94);
        color: var(--hb-ink);
        padding: 11px 14px;
        font: inherit;
        outline: 0;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .72);
        transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
    }

    input:focus {
        border-color: rgba(123, 201, 140, .72);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(123, 201, 140, .14);
    }

    button,
    .button {
        min-height: 44px;
        width: 100%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 18px;
        border: 1px solid transparent;
        border-radius: 999px;
        padding: 12px 18px;
        background: var(--hb-accent);
        color: var(--hb-accent-ink);
        font: inherit;
        font-weight: 800;
        text-decoration: none;
        cursor: pointer;
        box-shadow: 0 12px 40px rgba(16, 24, 40, .06);
        transition: transform .16s ease, border-color .16s ease, background .16s ease;
    }

    button:hover,
    .button:hover { transform: translateY(-1px); }

    .button.secondary {
        background: #fff;
        border-color: var(--hb-border);
        color: var(--hb-ink);
    }

    .error { color: #dc2626; font-size: .92rem; margin-top: 6px; font-weight: 650; }
</style>
