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
        --hb-line: rgba(45, 55, 72, .11);
        --hb-line-strong: rgba(45, 55, 72, .20);
    }

    * { box-sizing: border-box; }

    body {
        min-height: 100vh;
        margin: 0;
        display: grid;
        place-items: center;
        padding: 32px 18px;
        background: var(--hb-bg0);
        color: var(--hb-ink);
        font-family: "Plus Jakarta Sans", ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        line-height: 1.5;
    }

    .card {
        width: min(440px, 100%);
        padding: 28px 2px;
        border-top: 1px solid var(--hb-line-strong);
        border-bottom: 1px solid var(--hb-line-strong);
        border-radius: 0;
        background: transparent;
        box-shadow: none;
    }

    .card.center { text-align: center; }

    h1 {
        margin: 0 0 8px;
        color: var(--hb-ink);
        font-size: clamp(1.45rem, 4vw, 1.7rem);
        line-height: 1.12;
        font-weight: 650;
        letter-spacing: -.035em;
    }

    p { margin: 0; color: var(--hb-muted); }
    form { margin-top: 18px; display: grid; gap: 14px; }
    label {
        display: grid;
        gap: 6px;
        color: var(--hb-muted);
        font-size: .68rem;
        font-weight: 750;
        letter-spacing: .1em;
        text-transform: uppercase;
    }
    label span { color: var(--hb-muted); font-size: .78rem; font-weight: 600; }

    input {
        width: 100%;
        min-height: 48px;
        border: 0;
        border-bottom: 1px solid var(--hb-line-strong);
        border-radius: 0;
        background: transparent;
        color: var(--hb-ink);
        padding: 11px 2px;
        font: inherit;
        outline: 0;
        box-shadow: none;
        transition: border-color .16s ease, box-shadow .16s ease;
    }

    input:focus {
        border-color: rgba(123, 201, 140, .72);
        background: transparent;
        box-shadow: inset 0 -1px 0 var(--hb-accent-strong);
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
        border-radius: 2px;
        padding: 12px 18px;
        background: var(--hb-accent);
        color: var(--hb-accent-ink);
        font: inherit;
        font-weight: 800;
        text-decoration: none;
        cursor: pointer;
        box-shadow: none;
        transition: border-color .16s ease, background .16s ease;
    }

    button:hover,
    .button:hover { filter: saturate(1.06); }

    .button.secondary {
        background: transparent;
        border-color: var(--hb-line-strong);
        color: var(--hb-ink);
    }

    .error { color: #dc2626; font-size: .92rem; margin-top: 6px; font-weight: 650; }
</style>
