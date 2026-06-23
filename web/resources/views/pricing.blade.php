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
    <style>
        .pricing-page .segmented {
            overflow: visible;
        }
        .pricing-page .segmented input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }
        .pricing-page .segmented .billing-option {
            position: relative;
            z-index: 1;
            height: 34px;
            display: grid;
            place-items: center;
            border: 0;
            border-radius: 999px;
            background: transparent;
            color: var(--pb-muted);
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background .18s ease, color .18s ease;
        }
        .pricing-page .segmented input:checked + .billing-option,
        .pricing-page .segmented .billing-option.active {
            background: var(--pb-green);
            color: var(--pb-green-ink);
        }
        .pricing-page .segmented input:focus-visible + .billing-option {
            outline: 2px solid var(--pb-green-dark);
            outline-offset: 2px;
        }
        .section-head p {
            margin: 16px auto 0;
            color: var(--pb-green-dark);
            font-size: 18px;
            font-weight: 800;
        }
    </style>
</head>
<body class="pricing-page">
    @include('partials.public-beta-banner')
    @include('partials.public-nav')

    <section class="pricing-panel" id="plans">
        <div class="wrap">
            <div class="section-head">
                <span class="section-kicker">Pricing</span>
                <h2>Organized Your Days With Less Effort</h2>
                <p>14-day Free Trial - cancel anytime</p>
            </div>

            <div class="billing-switch" aria-label="Billing options">
                <div class="segmented" role="group" aria-label="Billing interval">
                    <input id="billing-monthly" type="radio" name="billing_interval" value="monthly" data-billing-option checked>
                    <label class="billing-option active" for="billing-monthly" data-billing-label="monthly" aria-pressed="true">Monthly</label>
                    <input id="billing-yearly" type="radio" name="billing_interval" value="yearly" data-billing-option>
                    <label class="billing-option" for="billing-yearly" data-billing-label="yearly" aria-pressed="false">Yearly</label>
                    <strong class="save-badge">Save over 16%</strong>
                </div>
            </div>
            @if ($fromFlutter)
                <p class="hero-subhead" style="font-size:15px;text-align:center;margin-top:-30px;margin-bottom:44px"><strong>Coming from the app?</strong> After upgrading on the site, close and reopen the Flutter app to apply your upgrade.</p>
            @endif

            <div class="plans">
                <article class="plan">
                    <h3>Base</h3>
                    <p class="for">Best for getting your personal day into one organized place.</p>
                    <div class="price"><span class="amount" data-monthly-price="$4.99" data-yearly-price="$49.99">$4.99</span><span class="period" data-monthly-period="/month" data-yearly-period="/year">/month</span></div>
                    <ul class="features">
                        <li>2 workspaces for personal and shared planning</li>
                        <li>Tasks, reminders, and calendar in one daily view</li>
                        <li>Bean chat and voice for everyday requests</li>
                        <li>1 connected calendar</li>
                        <li>Push reminders for the things you cannot miss</li>
                        <li>Recent history so Bean can follow the thread of your day</li>
                    </ul>
                    <a class="button" data-plan-link="base" href="/register?plan=base&billing_interval=monthly">Start 14 day free trial <span aria-hidden="true">-></span></a>
                    <p class="fine">$0.00 due today, cancel anytime</p>
                </article>

                <article class="plan popular">
                    <h3>Premium <span class="badge">Most popular</span></h3>
                    <p class="for">Best for families and power users who want Bean in the daily routine.</p>
                    <div class="price"><span class="amount" data-monthly-price="$19.99" data-yearly-price="$199.99">$19.99</span><span class="period" data-monthly-period="/month" data-yearly-period="/year">/month</span></div>
                    <ul class="features">
                        <li>5 workspaces for home, work, school, and projects</li>
                        <li>Expanded Bean capacity for everyday planning</li>
                        <li>Push and email reminders working together</li>
                        <li>Recurring tasks and reminders for repeated routines</li>
                        <li>Multiple calendar connections</li>
                        <li>1 year of searchable context and history</li>
                        <li>The best fit for most busy households</li>
                    </ul>
                    <a class="button" data-plan-link="premium" href="/register?plan=premium&billing_interval=monthly">Start 14 day free trial <span aria-hidden="true">-></span></a>
                    <p class="fine">$0.00 due today, cancel anytime</p>
                </article>

                <article class="plan">
                    <h3>Pro</h3>
                    <p class="for">Best for people who want Bean across every workspace and workflow.</p>
                    <div class="price"><span class="amount" data-monthly-price="$49.99" data-yearly-price="$499.99">$49.99</span><span class="period" data-monthly-period="/month" data-yearly-period="/year">/month</span></div>
                    <ul class="features">
                        <li>Unlimited workspaces for every area of life</li>
                        <li>Maximum Bean capacity for high-volume days</li>
                        <li>More room for connected tools and background work</li>
                        <li>Unlimited connected accounts</li>
                        <li>Full memory and history</li>
                        <li>Priority background work</li>
                        <li>Priority support</li>
                    </ul>
                    <a class="button" data-plan-link="pro" href="/register?plan=pro&billing_interval=monthly">Start 14 day free trial <span aria-hidden="true">-></span></a>
                    <p class="fine">$0.00 due today, cancel anytime</p>
                </article>
            </div>
        </div>
    </section>

    <section class="cta-band">
        <div class="wrap">
            <h2>Shaping a larger rollout?</h2>
            <p class="hero-subhead">For teams or special requirements, contact us and we will help shape the right HeyBean setup.</p>
            <div class="hero-actions"><a class="button outline" href="mailto:support@heybean.org?subject=HeyBean%20Enterprise">Contact us</a></div>
        </div>
    </section>

    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. AI executive assistance for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a></span></footer>
    <script>
        (() => {
            const options = Array.from(document.querySelectorAll('input[data-billing-option]'));
            const labels = Array.from(document.querySelectorAll('[data-billing-label]'));
            const amounts = Array.from(document.querySelectorAll('.amount[data-monthly-price]'));
            const periods = Array.from(document.querySelectorAll('.period[data-monthly-period]'));
            const links = Array.from(document.querySelectorAll('[data-plan-link]'));
            const applyInterval = (interval) => {
                const normalized = interval === 'yearly' ? 'yearly' : 'monthly';
                options.forEach((option) => {
                    option.checked = option.value === normalized;
                });
                labels.forEach((label) => {
                    const active = label.dataset.billingLabel === normalized;
                    label.classList.toggle('active', active);
                    label.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
                amounts.forEach((amount) => {
                    amount.textContent = normalized === 'yearly' ? amount.dataset.yearlyPrice : amount.dataset.monthlyPrice;
                });
                periods.forEach((period) => {
                    period.textContent = normalized === 'yearly' ? period.dataset.yearlyPeriod : period.dataset.monthlyPeriod;
                });
                links.forEach((link) => {
                    const plan = link.dataset.planLink;
                    link.href = `/register?plan=${encodeURIComponent(plan)}&billing_interval=${normalized}`;
                });
            };
            options.forEach((option) => option.addEventListener('change', () => applyInterval(option.value)));
            applyInterval(new URLSearchParams(window.location.search).get('billing_interval'));
        })();
    </script>
</body>
</html>
