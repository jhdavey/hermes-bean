@php($trialDays = max(0, (int) config('services.stripe.trial_days', 7)))
@php($fromFlutter = request()->query('source') === 'flutter')
@php($billingInterval = request()->query('billing_interval') === 'yearly' ? 'yearly' : 'monthly')
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HeyBean Pricing – AI Executive Assistant for Work and Life</title>
    <meta name="description" content="Choose a HeyBean plan for organizing calendars, tasks, reminders, and everyday follow-through across work and home.">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#7bc98c">
    @include('partials.public-postbridge-styles')
    @include('partials.public-pricing-styles')
</head>
<body class="pricing-page">
    @include('partials.public-beta-banner')
    @include('partials.public-nav')

    @include('partials.public-pricing-plans')

    @include('partials.public-early-access')

    <section class="cta-band">
        <div class="wrap">
            <h2>Let Bean take the next few things off your mind.</h2>
            <p class="hero-subhead">Start with one request. Bean will help you turn it into an organized plan for what happens next.</p>
            <div class="hero-actions"><a class="button" href="#early-access">Request early access <span aria-hidden="true">→</span></a></div>
            <p class="hero-microcopy">24 of 100 spots left · 7-day free trial after plan selection</p>
        </div>
    </section>

    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. AI executive assistance for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a> · <a href="/#plans">Pricing</a> · <a href="/login">Log In</a></span></footer>
    @include('partials.public-pricing-script')
</body>
</html>
