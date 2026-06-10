@extends('legal.layout', ['title' => 'Support | HeyBean', 'description' => 'Get help with HeyBean account, privacy, deletion, and app support.'])

@section('content')
    <p class="eyebrow">HeyBean Support</p>
    <h1>Support</h1>
    <p class="effective">Effective date: May 16, 2026</p>

    <div class="card">
        <h2>Contact</h2>
        <p>Email <a href="mailto:support@heybean.org">support@heybean.org</a> for account access, password help, privacy requests, bug reports, and product questions.</p>
    </div>

    <h2>Common requests</h2>
    <ul>
        <li><strong>Account deletion:</strong> use Settings in the app or follow <a href="/account-deletion">account deletion instructions</a>.</li>
        <li><strong>Subscription cancellation:</strong> if you subscribed through Apple, manage or cancel the subscription in your Apple ID subscription settings. If you subscribed through the HeyBean website, email <a href="mailto:support@heybean.org">support@heybean.org</a> and include the email address on your account.</li>
        <li><strong>Privacy questions:</strong> review the <a href="/privacy">Privacy Policy</a> or contact support.</li>
        <li><strong>Terms:</strong> review the <a href="/terms">Terms of Use</a>.</li>
        <li><strong>Calendar sync:</strong> disconnect calendar integrations from the app settings if you no longer want calendar sync.</li>
    </ul>
@endsection
