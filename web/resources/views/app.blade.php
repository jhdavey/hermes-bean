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
    @include('partials.public-bean-critical-styles')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="heybean-app-body">
    @if (request()->is('register'))
        @php
            $registerSource = preg_replace('/[^a-z0-9_\-]/i', '', (string) (request()->query('from') ?: request()->query('source') ?: 'direct_register')) ?: 'direct_register';
        @endphp
        @include('partials.public-bean-presence', [
            'class' => 'public-bean-presence-signup',
            'context' => 'signup_onboarding',
            'status' => 'Tap to wake up',
            'help' => 'Type these quick details. Bean will chime back in.',
            'ariaLabel' => 'Talk with Bean',
        ])
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
