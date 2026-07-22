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
            @foreach ($publicPricingPlans as $plan)
                <article @class(['plan', 'popular' => $plan['popular']])>
                    <h3>
                        {{ $plan['name'] }}
                        @if ($plan['popular'])
                            <span class="badge">Most popular</span>
                        @endif
                    </h3>
                    <p class="for">{{ $plan['description'] }}</p>
                    <div class="price">
                        <span class="amount">
                            <span class="monthly-price">${{ $plan['monthly_price'] }}</span>
                            <span class="yearly-price">${{ $plan['yearly_price'] }}</span>
                        </span>
                        <span class="period">
                            <span class="monthly-period">/month</span>
                            <span class="yearly-period">/year</span>
                        </span>
                    </div>
                    <ul class="features">
                        @foreach ($plan['features'] as $feature)
                            <li @class(['is-unavailable' => ! $feature['included']])>{{ $feature['label'] }}</li>
                        @endforeach
                    </ul>
                    <div class="plan-actions">
                        <a class="button monthly-link" data-plan-link="{{ $plan['key'] }}" href="/register?plan={{ $plan['key'] }}&billing_interval=monthly">Create your free beta account <span aria-hidden="true">→</span></a>
                        <a class="button yearly-link" href="/register?plan={{ $plan['key'] }}&billing_interval=yearly">Create your free beta account <span aria-hidden="true">→</span></a>
                    </div>
                    <p class="fine">$0.00 due today, cancel anytime</p>
                </article>
            @endforeach
        </div>
    </div>
</section>
