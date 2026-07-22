<section class="section early-access-section" id="early-access">
    <div class="wrap early-access-layout">
        <div class="early-access-copy">
            <span class="section-kicker" style="text-align:left">LIMITED EARLY ACCESS</span>
            <div class="early-access-count"><strong>24</strong> of 100 spots left</div>
            <h2>A careful rollout, so Bean can get better with every person.</h2>
            <p>I’m building HeyBean as a solo developer. I’m opening access gradually so I can support each new group, learn what needs work, and keep the experience reliable.</p>
            <p>Your spot includes account setup and a seven-day free trial after you choose a plan. If this group fills, you can still join the waitlist and I’ll let you know as soon as access opens again.</p>
        </div>
        <form class="early-access-form" method="POST" action="{{ route('early-access.store') }}">
            @csrf
            <label for="early-access-email">Reserve your early-access spot</label>
            <p>Enter your email to continue. No payment is collected here.</p>
            <div class="early-access-input-row">
                <input id="early-access-email" name="email" type="email" inputmode="email" autocomplete="email" placeholder="you@example.com" value="{{ old('email') }}" required>
                <button class="button" type="submit">Continue <span aria-hidden="true">→</span></button>
            </div>
            @error('email')<div class="early-access-message is-error" role="alert">{{ $message }}</div>@enderror
            @if (session('early_access_status'))<div class="early-access-message" role="status">{{ session('early_access_status') }}</div>@endif
            <small>7-day free trial after plan selection · Cancel anytime</small>
        </form>
    </div>
</section>
