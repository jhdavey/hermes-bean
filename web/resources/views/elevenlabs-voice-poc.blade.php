<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#8FB996">
        <title>Bean ElevenLabs Voice POC</title>
        @vite(['resources/css/app.css', 'resources/js/elevenlabsVoicePoc.js'])
    </head>
    <body class="antialiased">
        <main class="hb-app-shell hb-public-shell">
            <section class="hb-hero-card hb-surface hb-card-pad" style="max-width: 760px; margin: 4rem auto;">
                <p class="hb-eyebrow">HeyBean internal POC</p>
                <h1>ElevenLabs Speech Engine voice test</h1>
                <p class="hb-muted">
                    This page tests ElevenLabs managing the realtime voice loop while Bean remains the backend brain.
                    You must be signed in to Bean in this browser before starting.
                </p>

                <div id="bean-elevenlabs-voice-poc" class="hb-surface-soft hb-card-pad" style="margin-top: 1.5rem;">
                    <p>Status: <strong data-poc-status>Idle</strong></p>
                    <p class="hb-muted" data-poc-detail>Waiting to start.</p>
                    <div class="hb-button-row" style="margin-top: 1rem;">
                        <button class="hb-button" type="button" data-poc-start>Start ElevenLabs voice POC</button>
                        <button class="hb-button-ghost" type="button" data-poc-stop disabled>Stop</button>
                    </div>
                    <div class="hb-field" style="margin-top: 1rem;">
                        <label class="hb-label" for="poc-log">Transcript / event log</label>
                        <textarea id="poc-log" class="hb-input" data-poc-log rows="12" readonly></textarea>
                    </div>
                </div>

                <p class="hb-muted" style="margin-top: 1rem;">
                    Test script: “Can you hear me?”, “What tasks do I have today?”, “What about tomorrow?”,
                    “Create a task called test voice task”, “Mark it complete”, then interrupt/stop.
                </p>
            </section>
        </main>
    </body>
</html>
