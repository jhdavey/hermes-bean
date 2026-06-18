<!DOCTYPE html>
<html lang="en">
@php($fromFlutter = request()->query('source') === 'flutter')
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pricing | HeyBean</title>
    <meta name="description" content="Choose a HeyBean plan for AI calendar, tasks, reminders, voice chat, and workspace coordination.">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#7bc98c">
    @include('partials.public-postbridge-styles')
</head>
<body class="pricing-page">
    @include('partials.public-beta-banner')
    @include('partials.public-nav')

    <section class="pricing-panel" id="plans">
        <div class="wrap">
            <div class="section-head">
                <span class="section-kicker">Pricing</span>
                <h2>Organized Your Days With Less Effort</h2>
            </div>

            <div class="billing-switch" aria-label="Plan options">
                <div class="segmented" aria-hidden="true">
                    <span>Monthly</span>
                    <span>Yearly</span>
                    <strong class="save-badge">Save up to 17%</strong>
                </div>
                <span class="trial-toggle">Free trial <i class="toggle-ui" aria-hidden="true"></i></span>
            </div>
            @if ($fromFlutter)
                <p class="hero-subhead" style="font-size:15px;text-align:center;margin-top:-30px;margin-bottom:44px"><strong>Coming from the app?</strong> After upgrading on the site, close and reopen the Flutter app to apply your upgrade.</p>
            @endif

            <div class="plans">
                <article class="plan">
                    <h3>Base</h3>
                    <p class="for">Best for getting your personal day into one organized place.</p>
                    <div class="price"><span class="amount">$4.99</span><span class="period">/month</span></div>
                    <ul class="features">
                        <li>2 workspaces for personal and shared planning</li>
                        <li>Tasks, reminders, and calendar in one daily view</li>
                        <li>Bean chat and voice for everyday requests</li>
                        <li>1 connected calendar</li>
                        <li>Push reminders for the things you cannot miss</li>
                        <li>Recent history so Bean can follow the thread of your day</li>
                    </ul>
                    <a class="button" href="/register?plan=base">Start 7 day free trial <span aria-hidden="true">-></span></a>
                    <p class="fine">$0.00 due today, cancel anytime</p>
                </article>

                <article class="plan popular">
                    <h3>Premium <span class="badge">Most popular</span></h3>
                    <p class="for">Best for families and power users who want Bean in the daily routine.</p>
                    <div class="price"><span class="amount">$19.99</span><span class="period">/month</span></div>
                    <ul class="features">
                        <li>5 workspaces for home, work, school, and projects</li>
                        <li>Expanded Bean capacity for everyday planning</li>
                        <li>Push and email reminders working together</li>
                        <li>Recurring tasks and reminders for repeated routines</li>
                        <li>Multiple calendar connections</li>
                        <li>1 year of searchable context and history</li>
                        <li>The best fit for most busy households</li>
                    </ul>
                    <a class="button" href="/register?plan=premium">Start 7 day free trial <span aria-hidden="true">-></span></a>
                    <p class="fine">$0.00 due today, cancel anytime</p>
                </article>

                <article class="plan">
                    <h3>Pro</h3>
                    <p class="for">Best for people who want Bean across every workspace and workflow.</p>
                    <div class="price"><span class="amount">$49.99</span><span class="period">/month</span></div>
                    <ul class="features">
                        <li>Unlimited workspaces for every area of life</li>
                        <li>Maximum Bean capacity for high-volume days</li>
                        <li>More room for connected tools and background work</li>
                        <li>Unlimited connected accounts</li>
                        <li>Full memory and history</li>
                        <li>Priority background work</li>
                        <li>Priority support</li>
                    </ul>
                    <a class="button" href="/register?plan=pro">Start 7 day free trial <span aria-hidden="true">-></span></a>
                    <p class="fine">$0.00 due today, cancel anytime</p>
                </article>
            </div>
        </div>
    </section>

    <section class="cta-band">
        <div class="wrap">
            <h2>Still shaping a larger rollout?</h2>
            <p class="hero-subhead">For teams or special requirements, contact us and we will help shape the right HeyBean setup.</p>
            <div class="hero-actions"><a class="button outline" href="mailto:support@heybean.org?subject=HeyBean%20Enterprise">Contact us</a></div>
        </div>
    </section>

    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. AI executive assistance for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a></span></footer>
</body>
</html>
