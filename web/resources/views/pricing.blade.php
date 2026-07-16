<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pricing | HeyBean</title><meta name="description" content="Choose a HeyBean plan for calendars, tasks, reminders, notes, and workspace coordination.">
    <link rel="icon" href="{{ asset('favicon.ico') }}">@include('partials.public-postbridge-styles')
</head>
<body>
    <div class="public-beta-banner"><div class="public-beta-banner-inner">HeyBean is currently in Beta. <a href="/register">Create your beta account.</a></div></div>
    <header class="wrap nav"><a class="brand" href="/"><img src="{{ asset('images/bean-logo.png') }}" alt="HeyBean logo">HeyBean</a><nav class="navlinks"><a href="/#features">Features</a><a href="/#reviews">Reviews</a><a href="/pricing">Pricing</a><a class="nav-login" href="/login">Login</a></nav></header>
    <main>
        <section class="wrap hero pricing-hero"><h1>Organize Your Days With Less Effort</h1><p class="hero-subhead">Start with a 7-day free trial. Choose the workspace, calendar, reminder, note, and history limits that fit your life.</p></section>
        <section class="pricing-panel"><div class="wrap">
            @if(request('source') === 'flutter')<div class="feature-card" style="margin-bottom:26px">After upgrading on the site, close and reopen the Flutter app to apply your upgrade.</div>@endif
            <div class="billing-switch"><div class="segmented"><button class="active" type="button" data-billing="monthly">Monthly</button><button type="button" data-billing="yearly">Yearly · Save over 16%</button></div></div>
            <div class="plans">
                @php
                    $plans = [
                        'base' => ['Base', '4.99', '49.99', 'For personal planning in one organized place.', ['Tasks, reminders, and calendar', '2 workspaces and 1 connected calendar', 'Up to 10 Notes for plans, lists, and longer writing', 'Push reminders and recent history']],
                        'premium' => ['Premium', '19.99', '199.99', 'Best for busy households and recurring routines.', ['5 workspaces', 'Unlimited Notes with folders for plans, lists, and longer writing', 'Push and email reminders', 'Multiple calendars and 1 year of history']],
                        'pro' => ['Pro', '49.99', '499.99', 'For coordinating every part of life.', ['Unlimited workspaces', 'Unlimited Notes across every workspace', 'Unlimited connected accounts', 'Full history and priority support']],
                    ];
                @endphp
                @foreach($plans as $key => $plan)
                    <article class="plan {{ $key === 'premium' ? 'popular' : '' }}"><h3>{{ $plan[0] }} @if($key === 'premium')<span class="badge">Most popular</span>@endif</h3><p class="for">{{ $plan[3] }}</p><div class="price"><span class="amount" data-monthly="${{ $plan[1] }}" data-yearly="${{ $plan[2] }}">${{ $plan[1] }}</span><span class="period">/mo</span></div><ul class="features">@foreach($plan[4] as $feature)<li>{{ $feature }}</li>@endforeach</ul><a class="button" href="/register?plan={{ $key }}&billing_interval=monthly" data-plan-link data-plan="{{ $key }}">Start 7 day free trial</a><p class="fine">$0.00 due today, cancel anytime</p></article>
                @endforeach
            </div>
            <div class="cta-band"><h2>Need an enterprise plan?</h2><p><a class="button outline" href="mailto:support@heybean.org">Contact us</a></p></div>
        </div></section>
    </main>
    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. Productivity for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a></span></footer>
    <script>document.querySelectorAll('[data-billing]').forEach(button=>button.addEventListener('click',()=>{const yearly=button.dataset.billing==='yearly';document.querySelectorAll('[data-billing]').forEach(item=>item.classList.toggle('active',item===button));document.querySelectorAll('.amount').forEach(item=>item.textContent=yearly?item.dataset.yearly:item.dataset.monthly);document.querySelectorAll('.period').forEach(item=>item.textContent=yearly?'/yr':'/mo');document.querySelectorAll('[data-plan-link]').forEach(link=>link.href=`/register?plan=${link.dataset.plan}&billing_interval=${yearly?'yearly':'monthly'}`)}));</script>
</body>
</html>
