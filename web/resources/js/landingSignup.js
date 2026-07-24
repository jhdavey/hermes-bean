import '../css/app.css';
import { mountHeyBeanWebApp } from './heybean/webApp.js';

const mount = document.getElementById('heybean-web-app');
const signupFlow = document.querySelector('[data-landing-signup-flow]');
const landingContent = document.querySelector('[data-public-landing-content]');
const signupPathPattern = /^\/register(?:[?#]|$)/;
let inlineSignupStarted = false;

if (mount && signupFlow) {
    mountHeyBeanWebApp(mount);
    bindInlineSignupEntry();
}

function bindInlineSignupEntry() {
    document.addEventListener('click', (event) => {
        const link = event.target?.closest?.('a[href]');
        if (!link || shouldIgnoreLinkClick(event, link)) return;
        const href = link.getAttribute('href') || '';
        if (!signupPathPattern.test(href)) return;
        event.preventDefault();
        startInlineSignup(href, { sourceElement: link });
    });

    window.addEventListener('bean:request-inline-signup', (event) => {
        const href = event.detail?.href || '/register?from=bean';
        event.preventDefault();
        startInlineSignup(href, { source: event.detail?.source || 'bean' });
    });

    window.addEventListener('popstate', () => {
        if (window.location.pathname !== '/register' || inlineSignupStarted) return;
        startInlineSignup(`${window.location.pathname}${window.location.search}`, { replaceHistory: true });
    });
}

function shouldIgnoreLinkClick(event, link) {
    return event.defaultPrevented
        || event.button !== 0
        || event.metaKey
        || event.ctrlKey
        || event.shiftKey
        || event.altKey
        || link.target === '_blank'
        || link.hasAttribute('download');
}

function startInlineSignup(href, options = {}) {
    const url = new URL(href, window.location.origin);
    const detail = signupDetailFromUrl(url, options);
    inlineSignupStarted = true;

    window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;

    document.body.classList.add('public-signup-transitioning', 'heybean-app-body');
    signupFlow.hidden = false;
    signupFlow.scrollTop = 0;
    signupFlow.setAttribute('aria-hidden', 'false');
    landingContent?.setAttribute('aria-hidden', 'true');

    window.dispatchEvent(new CustomEvent('bean:inline-signup-started', { detail }));

    const nextUrl = `${url.pathname}${url.search || '?from=bean'}`;
    if (options.replaceHistory === true) {
        history.replaceState({ inlineSignup: true }, '', nextUrl);
    } else if (window.location.pathname !== url.pathname || window.location.search !== url.search) {
        history.pushState({ inlineSignup: true }, '', nextUrl);
    }

    window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;
    window.requestAnimationFrame(() => {
        document.body.classList.add('public-signup-active');
        window.setTimeout(() => {
            document.body.classList.remove('public-signup-transitioning');
            focusInlineSignupInput();
        }, 460);
    });
}

function signupDetailFromUrl(url, options = {}) {
    const params = url.searchParams;
    const plan = ['base', 'premium', 'pro'].includes(params.get('plan')) ? params.get('plan') : '';
    const billingInterval = params.get('billing_interval') === 'yearly' ? 'yearly' : 'monthly';
    const fallbackSource = options.source || options.sourceElement?.dataset?.signupSource || params.get('from') || 'landing_inline';
    return {
        href: `${url.pathname}${url.search}`,
        source: fallbackSource,
        plan,
        billingInterval,
        email: params.get('email') || '',
        themeMode: currentPublicThemeMode(),
    };
}

function currentPublicThemeMode() {
    const mode = String(window.__heybeanPublicThemeMode || document.documentElement.dataset.publicThemeMode || document.body?.dataset.publicThemeMode || '').trim().toLowerCase();
    if (['light', 'dark', 'auto'].includes(mode)) return mode;
    try {
        const stored = window.localStorage?.getItem('heybean.public.themeMode');
        if (['light', 'dark', 'auto'].includes(String(stored || '').trim().toLowerCase())) return String(stored).trim().toLowerCase();
    } catch (_) {}
    return 'light';
}

function focusInlineSignupInput() {
    const target = signupFlow.querySelector('[data-action="guided-onboarding"] [name="value"], [data-guided-theme-mode], .hb-guided-chat-composer');
    try {
        target?.focus?.({ preventScroll: true });
    } catch (_) {
        target?.focus?.();
    }
}
