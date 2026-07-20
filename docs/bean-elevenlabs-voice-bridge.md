# Bean ElevenLabs Voice Bridge

Bean voice now uses ElevenLabs Speech Engine for realtime audio, STT, TTS, and conversational turn-taking. OpenAI remains available to Hermes as the reasoning/model provider; the browser voice path no longer calls OpenAI Realtime.

## Runtime shape

```text
Browser HeyBean app
  -> /api/bean/elevenlabs/conversation-token (authenticated Laravel)
  -> ElevenLabs Conversation WebRTC session
  -> ElevenLabs Speech Engine
  -> Bean ElevenLabs voice bridge (Node WebSocket server)
  -> /api/bean/elevenlabs/bridge/message (Laravel, X-Bean-Voice-Bridge-Secret)
  -> BeanRuntimeService / Hermes / bean_dashboard tools
```

Laravel owns authentication, user/session mapping, workspace scope, timezone normalization, persistence, and telemetry. The Node bridge has no user bearer tokens; it can only call the internal Laravel bridge endpoint with `ELEVENLABS_VOICE_BRIDGE_SECRET`.

## Required production env

In `/home/forge/heybean.org/current/web/.env`:

```bash
ELEVENLABS_API_KEY=...
ELEVENLABS_SPEECH_ENGINE_ID=seng_...
ELEVENLABS_SPEECH_ENGINE_ENABLED=true
ELEVENLABS_VOICE_BRIDGE_SECRET=<long random secret>
ELEVENLABS_SPEECH_ENGINE_PORT=3001
ELEVENLABS_SPEECH_ENGINE_HOST=0.0.0.0
ELEVENLABS_SPEECH_ENGINE_PATH=/ws
BEAN_API_BASE_URL=https://heybean.org/api
```

## Process command

From `/home/forge/heybean.org/current/web`:

```bash
ELEVENLABS_API_KEY="$(php -r '$env=parse_ini_file(".env", false, INI_SCANNER_RAW) ?: []; echo $env["ELEVENLABS_API_KEY"] ?? "";')" \
ELEVENLABS_SPEECH_ENGINE_ID="$(php -r '$env=parse_ini_file(".env", false, INI_SCANNER_RAW) ?: []; echo $env["ELEVENLABS_SPEECH_ENGINE_ID"] ?? "";')" \
BEAN_VOICE_BRIDGE_SECRET="$(php -r '$env=parse_ini_file(".env", false, INI_SCANNER_RAW) ?: []; echo $env["ELEVENLABS_VOICE_BRIDGE_SECRET"] ?? "";')" \
BEAN_API_BASE_URL="https://heybean.org/api" \
ELEVENLABS_SPEECH_ENGINE_HOST="0.0.0.0" \
ELEVENLABS_SPEECH_ENGINE_PORT="3001" \
ELEVENLABS_SPEECH_ENGINE_PATH="/ws" \
npm run elevenlabs:voice-bridge
```

Current no-sudo production fallback uses `pm2` as the `forge` user:

```bash
pm2 start npm --name bean-elevenlabs-voice-bridge -- run elevenlabs:voice-bridge
pm2 save
```

Preferred Forge UI daemon command is the same environment-wrapped command above, or an Nginx reverse proxy to a localhost-bound process:

```nginx
location /bean/elevenlabs/speech-engine/ws {
    proxy_pass http://127.0.0.1:3001/ws;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
}
```

If that proxy is installed, set ElevenLabs Speech Engine `wsUrl` to:

```text
wss://heybean.org/bean/elevenlabs/speech-engine/ws
```

Without sudo/Nginx access, the bridge can bind publicly and ElevenLabs can use:

```text
ws://143.244.184.227:3001/ws
```

The bridge still verifies ElevenLabs' signed Speech Engine JWT before accepting WebSocket sessions.

## Verification

```bash
curl http://127.0.0.1:3001/healthz
php artisan route:list --path=bean/elevenlabs
npm test
php artisan test --filter='BeanElevenLabsSpeechEnginePocTest|BeanHermesRuntimeTest'
npm run build
```

Then collect live samples per `docs/bean-voice-live-sample-harness.md` and rerun:

```bash
php artisan bean:ux-benchmark --days=7
php artisan bean:ux-evaluate-scenarios --recent=500
```
