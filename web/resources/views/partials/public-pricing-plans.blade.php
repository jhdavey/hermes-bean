@php($trialDays = $trialDays ?? max(0, (int) config('services.stripe.trial_days', 7)))
@php($fromFlutter = $fromFlutter ?? request()->query('source') === 'flutter')
@php($billingInterval = $billingInterval ?? (request()->query('billing_interval') === 'yearly' ? 'yearly' : 'monthly'))
<section class="pricing-panel public-pricing" id="plans" aria-labelledby="pricing-heading">
    <div class="wrap">
        <div class="section-head">
            <span class="section-kicker">Pricing</span>
            <h2 id="pricing-heading">Choose the support your life needs.</h2>
            <p>{{ $trialDays }}-day free trial · Cancel anytime</p>
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
                    <li>1 connected calendar</li>
                    <li>Up to 10 notes for plans, lists, and longer writing</li>
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
