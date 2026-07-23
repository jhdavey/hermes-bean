<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HeyBean App</title>
    <meta name="description" content="HeyBean browser command center">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#7bc98c">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="heybean-app-body">
    @if (request()->is('register'))
        @include('partials.public-beta-banner')
        @php
            $registerSource = preg_replace('/[^a-z0-9_\-]/i', '', (string) (request()->query('from') ?: request()->query('source') ?: 'direct_register')) ?: 'direct_register';
        @endphp
        <div
            class="public-bean-presence public-bean-presence-signup"
            data-public-bean
            data-public-bean-context="signup_onboarding"
            data-mode="disabled"
            data-csrf-token="{{ csrf_token() }}"
            data-conversation-token-url="{{ route('bean.landing.conversation-token') }}"
            data-message-url="{{ route('bean.landing.messages') }}"
            data-voice-event-url="{{ route('bean.landing.voice-events') }}"
            data-turnstile-site-key="{{ config('services.turnstile.site_key') }}"
        >
            <span class="public-bean-ring" aria-hidden="true"></span>
            <button class="public-bean-control" type="button" data-public-bean-toggle aria-pressed="false" aria-label="Talk with Bean">
                <span class="public-bean-icon"><img src="{{ asset('images/bean-logo.png') }}" alt="Bean"></span>
                <span class="public-bean-status" data-public-bean-status aria-live="polite">Tap to talk</span>
            </button>
            <span class="public-bean-help" data-public-bean-help>Turn your volume on and allow microphone access. Bean will talk you through signup, but type answers into the box.</span>
            <span class="public-bean-turnstile" data-public-bean-turnstile hidden></span>
        </div>
        @vite('resources/js/publicBean.js')
    @endif
    <div
        id="heybean-web-app"
        data-logo="{{ asset('images/bean-logo.png') }}"
        data-auth-mode="{{ request()->is('subscribe') ? 'subscribe' : (request()->is('register') ? (request()->query('mode') === 'plain' ? 'plain' : 'register') : (request()->is('forgot-password') ? 'forgot' : 'login')) }}"
        data-from-landing-bean="{{ request()->query('from') === 'bean' ? 'true' : 'false' }}"
        data-signup-source="{{ $registerSource ?? 'direct_register' }}"
        data-selected-plan="{{ in_array(request()->query('plan'), ['base', 'premium', 'pro'], true) ? request()->query('plan') : '' }}"
        data-selected-billing-interval="{{ request()->query('billing_interval') === 'yearly' ? 'yearly' : 'monthly' }}"
    >
        <div class="hb-loading-screen">
            <div class="hb-spinner" aria-hidden="true"></div>
            <p>Loading HeyBean…</p>
        </div>
    </div>
</body>
</html>
