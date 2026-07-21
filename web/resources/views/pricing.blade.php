@php($trialDays = max(0, (int) config('services.stripe.trial_days', 7)))
<!DOCTYPE html>
<html lang="en">
@php($fromFlutter = request()->query('source') === 'flutter')
@php($billingInterval = request()->query('billing_interval') === 'yearly' ? 'yearly' : 'monthly')
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
            z-index: 2;
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
            transition: color .18s ease;
        }
        .pricing-page .segmented input:checked + .billing-option,
        .pricing-page .segmented .billing-option.active {
            color: var(--pb-green-ink);
        }
        .pricing-page .segmented input:focus-visible + .billing-option {
            outline: 2px solid var(--pb-green-dark);
            outline-offset: 2px;
        }
        .pricing-page .billing-toggle-thumb {
            position: absolute;
            z-index: 1;
            top: 4px;
            left: 4px;
            width: calc((100% - 8px) / 2);
            height: 34px;
            display: block;
            border-radius: 999px;
            background: var(--pb-green);
            box-shadow: 0 1px 2px rgba(16, 24, 40, .08);
            transition: transform .24s cubic-bezier(.2, .8, .2, 1);
        }
        .pricing-page .segmented #billing-yearly:checked ~ .billing-toggle-thumb {
            transform: translateX(100%);
        }
        .pricing-page .yearly-price,
        .pricing-page .yearly-period,
        .pricing-page .yearly-link {
            display: none;
        }
        .pricing-page:has(#billing-yearly:checked) .monthly-price,
        .pricing-page:has(#billing-yearly:checked) .monthly-period,
        .pricing-page:has(#billing-yearly:checked) .monthly-link {
            display: none;
        }
        .pricing-page:has(#billing-yearly:checked) .yearly-price,
        .pricing-page:has(#billing-yearly:checked) .yearly-period,
        .pricing-page:has(#billing-yearly:checked) .yearly-link {
            display: inline-flex;
        }
        .pricing-page .price .yearly-price,
        .pricing-page .price .yearly-period {
            display: none;
        }
        .pricing-page:has(#billing-yearly:checked) .price .yearly-price,
        .pricing-page:has(#billing-yearly:checked) .price .yearly-period {
            display: inline;
        }
        .pricing-page .plan-actions {
            width: 100%;
            display: grid;
            margin-top: auto;
        }
        .pricing-page .plan .plan-actions .button {
            margin-top: 0;
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
                <h2>Choose the support your life needs.</h2>
                <p>{{ $trialDays }}-day Free Trial - cancel anytime</p>
            </div>

            <div class="billing-switch" aria-label="Billing options">
                <div class="segmented" role="group" aria-label="Billing interval">
                    <input id="billing-monthly" type="radio" name="billing_interval" value="monthly" data-billing-option @checked($billingInterval === 'monthly')>
                    <label class="billing-option {{ $billingInterval === 'monthly' ? 'active' : '' }}" for="billing-monthly" data-billing-label="monthly" aria-pressed="{{ $billingInterval === 'monthly' ? 'true' : 'false' }}">Monthly</label>
                    <input id="billing-yearly" type="radio" name="billing_interval" value="yearly" data-billing-option @checked($billingInterval === 'yearly')>
                    <label class="billing-option {{ $billingInterval === 'yearly' ? 'active' : '' }}" for="billing-yearly" data-billing-label="yearly" aria-pressed="{{ $billingInterval === 'yearly' ? 'true' : 'false' }}">Yearly</label>
                    <span class="billing-toggle-thumb" aria-hidden="true"></span>
                    <strong class="save-badge">Save over 16%</strong>
                </div>
            </div>
            @if ($fromFlutter)
                <p class="hero-subhead" style="font-size:15px;text-align:center;margin-top:-30px;margin-bottom:44px"><strong>Coming from the app?</strong> After upgrading on the site, close and reopen the Flutter app to apply your upgrade.</p>
            @endif

            <div class="plans">
                <article class="plan">
                    <h3>Base</h3>
                    <p class="for">Best for one person coordinating work and personal life.</p>
                    <div class="price"><span class="amount"><span class="monthly-price">$4.99</span><span class="yearly-price">$49.99</span></span><span class="period"><span class="monthly-period">/month</span><span class="yearly-period">/year</span></span></div>
                    <ul class="features">
                        <li>2 workspaces for personal and shared planning</li>
                        <li>Tasks, reminders, and calendar in one daily view</li>
                        <li>Notes for plans, lists, and longer writing</li>
                        <li>1 connected calendar</li>
                        <li>Up to 10 Notes for plans, lists, and longer writing</li>
                        <li>Push reminders for the things you cannot miss</li>
                        <li>Recent calendar and task history</li>
                    </ul>
                    <div class="plan-actions">
                        <a class="button monthly-link" data-plan-link="base" href="/register?plan=base&billing_interval=monthly">Create your free beta account <span aria-hidden="true">→</span></a>
                        <a class="button yearly-link" href="/register?plan=base&billing_interval=yearly">Create your free beta account <span aria-hidden="true">→</span></a>
                    </div>
                    <p class="fine">$0.00 due today, cancel anytime</p>
                </article>

                <article class="plan popular">
                    <h3>Premium <span class="badge">Most popular</span></h3>
                    <p class="for">Best for busy households coordinating more people and responsibilities.</p>
                    <div class="price"><span class="amount"><span class="monthly-price">$19.99</span><span class="yearly-price">$199.99</span></span><span class="period"><span class="monthly-period">/month</span><span class="yearly-period">/year</span></span></div>
                    <ul class="features">
                        <li>5 workspaces for home, work, school, and projects</li>
                        <li>More space for everyday planning</li>
                        <li>Push and email reminders working together</li>
                        <li>Recurring tasks and reminders for repeated routines</li>
                        <li>Unlimited Notes with folders for plans, lists, and longer writing</li>
                        <li>Multiple calendar connections</li>
                        <li>1 year of searchable context and history</li>
                        <li>The best fit for most busy households</li>
                    </ul>
                    <div class="plan-actions">
                        <a class="button monthly-link" data-plan-link="premium" href="/register?plan=premium&billing_interval=monthly">Create your free beta account <span aria-hidden="true">→</span></a>
                        <a class="button yearly-link" href="/register?plan=premium&billing_interval=yearly">Create your free beta account <span aria-hidden="true">→</span></a>
                    </div>
                    <p class="fine">$0.00 due today, cancel anytime</p>
                </article>

                <article class="plan">
                    <h3>Pro</h3>
                    <p class="for">Best for complex schedules with more calendars, workspaces, and history.</p>
                    <div class="price"><span class="amount"><span class="monthly-price">$49.99</span><span class="yearly-price">$499.99</span></span><span class="period"><span class="monthly-period">/month</span><span class="yearly-period">/year</span></span></div>
                    <ul class="features">
                        <li>Unlimited workspaces for every area of life</li>
                        <li>Unlimited tasks, reminders, and events for high-volume days</li>
                        <li>More room for connected calendars and workspace planning</li>
                        <li>Unlimited connected accounts</li>
                        <li>Unlimited Notes across every workspace</li>
                        <li>Full calendar and task history</li>
                        <li>Priority support response</li>
                        <li>Priority support</li>
                    </ul>
                    <div class="plan-actions">
                        <a class="button monthly-link" data-plan-link="pro" href="/register?plan=pro&billing_interval=monthly">Create your free beta account <span aria-hidden="true">→</span></a>
                        <a class="button yearly-link" href="/register?plan=pro&billing_interval=yearly">Create your free beta account <span aria-hidden="true">→</span></a>
                    </div>
                    <p class="fine">$0.00 due today, cancel anytime</p>
                </article>
            </div>
        </div>
    </section>

    <section class="cta-band">
        <div class="wrap">
            <h2>Let Bean take the next few things off your mind.</h2>
            <p class="hero-subhead">Start with one request. Bean will help you turn it into an organized plan for what happens next.</p>
            <div class="hero-actions"><a class="button" href="/register">Create your free beta account <span aria-hidden="true">→</span></a></div>
            <p class="hero-microcopy">Free during beta</p>
        </div>
    </section>

    <footer class="wrap footer"><span>© {{ date('Y') }} HeyBean. AI executive assistance for real life.</span><span><a href="/privacy">Privacy Policy</a> · <a href="/terms">Terms of Use</a> · <a href="/support">Support</a> · <a href="/pricing">Pricing</a> · <a href="/login">Log In</a></span></footer>
    <script>
        (() => {
            const options = Array.from(document.querySelectorAll('input[data-billing-option]'));
            const labels = Array.from(document.querySelectorAll('[data-billing-label]'));
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
            };
            options.forEach((option) => option.addEventListener('change', () => applyInterval(option.value)));
            applyInterval(new URLSearchParams(window.location.search).get('billing_interval'));
        })();
    </script>
</body>
</html>
