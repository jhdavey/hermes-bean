@extends('legal.layout', ['title' => 'Privacy Policy | HeyBean', 'description' => 'How HeyBean collects, uses, and protects app data.'])

@section('content')
    <p class="eyebrow">HeyBean Privacy Policy</p>
    <h1>Privacy Policy</h1>
    <p class="effective">Effective date: May 16, 2026</p>

    <p>HeyBean is a personal AI assistant app for calendar planning, tasks, reminders, household coordination, and related productivity workflows. This policy explains what information we collect, how we use it, and the choices you have.</p>

    <h2>Information we collect</h2>
    <ul>
        <li><strong>Account information:</strong> name, email address, password hash, authentication tokens, and account settings.</li>
        <li><strong>Assistant and productivity content:</strong> chat messages, preferences, tasks, reminders, calendar events, approvals, blockers, workspace/household information, and activity logs you create or ask Bean to manage.</li>
        <li><strong>Connected calendar data:</strong> if you connect a third-party calendar, HeyBean may read calendar lists and events you authorize, create or update events you approve, and store non-secret sync metadata. OAuth tokens are stored encrypted and are not sold or used for advertising. HeyBean uses connected-service data only to provide and improve the features you request. HeyBean's use and transfer of information received from Google APIs adheres to the <a href="https://developers.google.com/terms/api-services-user-data-policy" rel="noopener noreferrer">Google API Services User Data Policy</a>, including the Limited Use requirements.</li>
        <li><strong>Technical data:</strong> device/app diagnostics, IP address, timestamps, API request metadata, and security logs needed to run and protect the service.</li>
        <li><strong>Early-access information:</strong> email addresses submitted on our website so we can send product invitations and launch updates.</li>
    </ul>

    <h2>How we use information</h2>
    <ul>
        <li>Provide the HeyBean app, including AI assistance, calendar planning, reminders, tasks, workspace syncing, and approvals.</li>
        <li>Authenticate users, secure accounts, prevent abuse, and troubleshoot reliability issues.</li>
        <li>Sync with services you connect, such as your calendar provider, based on your permissions and app actions.</li>
        <li>Respond to support requests and send essential product/account communications.</li>
        <li>Improve product quality using aggregated or de-identified operational information where possible.</li>
    </ul>

    <h2>AI processing</h2>
    <p>When you ask Bean to help, relevant prompts, assistant memory, and productivity data may be sent to AI model providers only as needed to generate responses or perform requested actions. We do not use your personal content for third-party advertising.</p>

    <h2>Sharing</h2>
    <p>We share data with service providers that help operate HeyBean, such as hosting, email, calendar APIs, AI model providers, analytics/diagnostics, and security tools. We may disclose information if required by law or to protect users, the service, or others.</p>

    <h2>Your choices</h2>
    <ul>
        <li>Use Settings in the app to update your email, disconnect calendar sync, or delete your account. You can request to export account data by emailing support until the in-app export control is available.</li>
        <li>Visit <a href="/account-deletion">Account deletion</a> for deletion instructions.</li>
        <li>Email <a href="mailto:support@heybean.org">support@heybean.org</a> for privacy or account requests.</li>
    </ul>

    <h2>Data retention and security</h2>
    <p>We keep information as long as needed to provide HeyBean, comply with legal obligations, resolve disputes, and maintain security. We use safeguards such as HTTPS, encrypted secrets where applicable, access controls, rate limits, and account deletion/export tools. No system is perfectly secure, so please use a strong unique password.</p>

    <h2>Children</h2>
    <p>HeyBean is not intended for children under 13. Do not use HeyBean if you are not old enough to consent to this policy in your location.</p>

    <h2>Contact</h2>
    <p>Questions or requests: <a href="mailto:support@heybean.org">support@heybean.org</a>.</p>
@endsection
