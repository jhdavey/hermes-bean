@php($isRegisterBanner = request()->is('register'))
<div class="public-beta-banner {{ $isRegisterBanner ? 'public-beta-banner-register' : '' }}" role="note">
    <div class="public-beta-banner-inner">
        @if ($isRegisterBanner)
            <span><span class="public-beta-banner-eyebrow">Early access</span> <strong>24 of 100 spots left</strong></span>
        @else
            <span>Limited early access: <strong>24 of 100 spots left.</strong> <a href="/register?from=beta_banner">Try it for free <span aria-hidden="true">→</span></a></span>
        @endif
    </div>
</div>
