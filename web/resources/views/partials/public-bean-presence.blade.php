@php
    $publicBeanClass = trim('public-bean-presence '.($class ?? ''));
    $publicBeanContext = $context ?? null;
    $publicBeanStatus = $status ?? 'Tap to wake up';
    $publicBeanHelp = $help ?? 'Volume on · allow mic';
    $publicBeanAria = $ariaLabel ?? 'Wake up landing page Bean';
@endphp
<div
    class="{{ $publicBeanClass }}"
    data-public-bean
    @if ($publicBeanContext) data-public-bean-context="{{ $publicBeanContext }}" @endif
    data-mode="disabled"
    data-csrf-token="{{ csrf_token() }}"
    data-conversation-token-url="{{ route('bean.landing.conversation-token') }}"
    data-message-url="{{ route('bean.landing.messages') }}"
    data-voice-event-url="{{ route('bean.landing.voice-events') }}"
    data-turnstile-site-key="{{ config('services.turnstile.site_key') }}"
>
    <button class="public-bean-control" type="button" data-public-bean-toggle aria-pressed="false" aria-label="{{ $publicBeanAria }}">
        <span class="public-bean-icon"><img src="{{ asset('images/bean-logo.png') }}" width="68" height="68" alt="Bean"></span>
    </button>
    <span class="public-bean-copy">
        <span class="public-bean-status" data-public-bean-status aria-live="polite">{{ $publicBeanStatus }}</span>
        <span class="public-bean-help" data-public-bean-help>{{ $publicBeanHelp }}</span>
    </span>
    <span class="public-bean-turnstile" data-public-bean-turnstile hidden></span>
</div>
