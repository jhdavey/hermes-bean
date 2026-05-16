@extends('legal.layout', ['title' => 'Terms of Use | HeyBean', 'description' => 'Terms governing use of the HeyBean app and website.'])

@section('content')
    <p class="eyebrow">HeyBean Terms</p>
    <h1>Terms of Use</h1>
    <p class="effective">Effective date: May 16, 2026</p>

    <p>These Terms of Use govern your access to and use of HeyBean, including the mobile app, API-backed assistant features, connected calendar features, and website. By using HeyBean, you agree to these terms.</p>

    <h2>Use of HeyBean</h2>
    <ul>
        <li>You must provide accurate account information and keep your login credentials secure.</li>
        <li>You are responsible for reviewing Bean’s suggestions, approvals, calendar changes, reminders, tasks, and other actions before relying on them.</li>
        <li>You may not misuse the service, attempt unauthorized access, disrupt infrastructure, reverse engineer restricted components, or use HeyBean for unlawful, harmful, or abusive activity.</li>
    </ul>

    <h2>AI assistant limitations</h2>
    <p>HeyBean uses AI and automation. Outputs may be incomplete, delayed, or incorrect. Do not rely on HeyBean for emergencies, medical advice, legal advice, financial advice, or other high-risk decisions. You should verify important calendar, task, reminder, and communication details.</p>

    <h2>Connected services</h2>
    <p>If you connect Google Calendar or another service, you authorize HeyBean to access and process data from that service as needed to provide requested features. Your use of connected services is also governed by their terms and policies. You can disconnect integrations in the app when available.</p>

    <h2>Your content</h2>
    <p>You retain ownership of your content. You grant HeyBean permission to host, process, transmit, and display your content as necessary to provide and improve the service, maintain security, and comply with law.</p>

    <h2>Subscriptions and launch status</h2>
    <p>HeyBean may be offered as a beta, early-access, free, or paid service. If paid features are introduced, pricing and payment terms will be disclosed before purchase.</p>

    <h2>Account deletion</h2>
    <p>You may delete your account in the app or request help through <a href="/account-deletion">Account deletion</a>. Deletion is permanent except for limited records we may retain for security, legal, backup, or fraud-prevention purposes.</p>

    <h2>Disclaimers and limitation of liability</h2>
    <p>HeyBean is provided “as is” and “as available.” To the maximum extent permitted by law, we disclaim warranties and are not liable for indirect, incidental, special, consequential, or punitive damages, or for lost data, missed reminders, calendar errors, or service interruptions.</p>

    <h2>Changes</h2>
    <p>We may update these terms. If changes are material, we will provide reasonable notice through the app, website, or email.</p>

    <h2>Contact</h2>
    <p>Questions: <a href="mailto:support@heybean.org">support@heybean.org</a>.</p>
@endsection
