@extends('legal.layout', ['title' => 'Account Deletion | HeyBean', 'description' => 'How to delete your HeyBean account and data.'])

@section('content')
    <p class="eyebrow">HeyBean Account Handling</p>
    <h1>Account deletion</h1>
    <p class="effective">Effective date: May 16, 2026</p>

    <h2>Delete your account in the app</h2>
    <ol>
        <li>Open HeyBean and sign in.</li>
        <li>Go to <strong>Settings</strong>.</li>
        <li>Open the <strong>Profile</strong> section.</li>
        <li>Tap <strong>Delete account</strong> and <strong>Type DELETE</strong> to confirm.</li>
    </ol>

    <h2>What deletion removes</h2>
    <p>Account deletion permanently deletes your HeyBean account, authentication tokens, tasks, reminders, calendar events, notes, workspace-owned personal data, and related app records associated with your account.</p>

    <h2>What may remain temporarily</h2>
    <p>Some limited records may remain for a short time in encrypted backups, security logs, fraud/abuse prevention records, or records we must keep for legal compliance. Shared household/workspace content may remain visible to other members when they own or still need access to that workspace.</p>

    <h2>Manual requests</h2>
    <p>If you cannot access the app, email <a href="mailto:support@heybean.org">support@heybean.org</a> from the email address on your account and request account deletion. You can also request a data export by email before deletion.</p>
@endsection
