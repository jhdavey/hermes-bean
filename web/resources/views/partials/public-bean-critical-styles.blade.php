<script src="{{ asset('js/public-bean-handoff.js') }}"></script>
<style>
    :root {
        --public-bean-shell-top: calc(env(safe-area-inset-top, 0px) + clamp(172px, 25vh, 194px));
        --public-bean-shell-left: 50%;
    }

    .public-bean-presence {
        --public-bean-accent: 82, 168, 105;
        position: fixed;
        top: calc(env(safe-area-inset-top, 0px) + 46px);
        left: max(24px, calc((100vw - var(--pb-max, 1152px)) / 2 + 24px));
        z-index: 70;
        width: 128px;
        min-width: 0;
        display: grid;
        justify-items: center;
        align-items: center;
        gap: 2px;
        border: 0;
        background: transparent;
        box-shadow: none;
        isolation: isolate;
        overflow: visible;
    }

    .public-bean-presence-hero,
    .public-bean-presence-signup {
        position: fixed;
        top: var(--public-bean-scroll-top, var(--public-bean-handoff-top, var(--public-bean-shell-top)));
        left: var(--public-bean-handoff-left, var(--public-bean-shell-left));
        z-index: 86;
        width: max-content;
        max-width: min(260px, calc(100vw - 48px));
        transform: translateX(-50%);
        transition: left .24s ease, transform .24s ease, max-width .24s ease;
    }

    body:has(.public-bean-presence-hero)::before {
        content: "";
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 85;
        height: calc(env(safe-area-inset-top, 0px) + 154px);
        pointer-events: none;
        background: linear-gradient(180deg, #fff 0%, rgba(255, 255, 255, .995) 70%, rgba(255, 255, 255, .94) 86%, rgba(255, 255, 255, 0) 100%);
        box-shadow: 0 18px 34px rgba(255, 255, 255, .92);
        opacity: 0;
        transition: opacity .24s ease;
    }

    body.public-bean-landing-compact::before {
        opacity: 1;
    }

    .public-bean-presence-hero[data-landing-scroll="compact"] {
        left: 50%;
        max-width: min(180px, calc(100vw - 40px));
    }

    .public-bean-presence-hero + .hero-icons {
        margin-top: 137px;
    }

    .public-bean-control {
        position: relative;
        width: 92px;
        height: 92px;
        min-width: 0;
        min-height: 0;
        display: grid;
        place-items: center;
        border: 0;
        padding: 0;
        background: transparent;
        color: inherit;
        cursor: pointer;
    }

    .public-bean-icon {
        width: 92px;
        height: 92px;
        display: grid;
        place-items: center;
        border: 0;
        background: transparent;
        overflow: visible;
    }

    .public-bean-icon img {
        width: 68px;
        height: 68px;
        max-width: 68px;
        max-height: 68px;
        display: block;
        object-fit: contain;
        opacity: .9;
    }

    .public-bean-presence-hero[data-landing-scroll="compact"] .public-bean-control,
    .public-bean-presence-hero[data-landing-scroll="compact"] .public-bean-icon {
        width: 64px;
        height: 64px;
    }

    .public-bean-presence-hero[data-landing-scroll="compact"] .public-bean-icon img {
        width: 46px;
        height: 46px;
        max-width: 46px;
        max-height: 46px;
    }

    .public-bean-copy {
        display: grid;
        justify-items: center;
        gap: 3px;
        width: max-content;
        max-width: min(260px, calc(100vw - 48px));
        text-align: center;
        pointer-events: none;
    }

    .public-bean-status,
    .public-bean-help {
        display: block;
        max-width: inherit;
        overflow: hidden;
        color: rgba(17, 19, 17, .42);
        font: 560 13px/1.22 "Plus Jakarta Sans", ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .public-bean-status {
        color: rgba(17, 19, 17, .58);
        font-size: 14px;
        font-weight: 620;
    }

    .public-bean-presence-hero[data-landing-scroll="compact"] .public-bean-copy {
        gap: 0;
        max-width: min(180px, calc(100vw - 40px));
    }

    .public-bean-presence-hero[data-landing-scroll="compact"] .public-bean-status {
        color: rgba(17, 19, 17, .54);
        font-size: 11px;
        font-weight: 650;
    }

    .public-bean-presence-hero[data-landing-scroll="compact"] .public-bean-help {
        display: none;
    }

    .public-bean-presence-signup .public-bean-status {
        display: none;
    }

    @media (max-width: 620px) {
        .public-bean-control,
        .public-bean-icon {
            width: 82px;
            height: 82px;
        }

        .public-bean-icon img {
            width: 60px;
            height: 60px;
            max-width: 60px;
            max-height: 60px;
        }

        body:has(.public-bean-presence-hero)::before {
            height: calc(env(safe-area-inset-top, 0px) + 190px);
        }

        .public-bean-presence-hero[data-landing-scroll="compact"] {
            max-width: min(160px, calc(100vw - 32px));
        }

        .public-bean-presence-hero[data-landing-scroll="compact"] .public-bean-control,
        .public-bean-presence-hero[data-landing-scroll="compact"] .public-bean-icon {
            width: 56px;
            height: 56px;
        }

        .public-bean-presence-hero[data-landing-scroll="compact"] .public-bean-icon img {
            width: 42px;
            height: 42px;
            max-width: 42px;
            max-height: 42px;
        }

        .public-bean-presence-hero + .hero-icons {
            margin-top: 133px;
        }

        .public-bean-status { font-size: 13px; }
        .public-bean-help { font-size: 12px; }
    }
</style>
