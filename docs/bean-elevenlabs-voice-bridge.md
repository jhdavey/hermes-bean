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
ELEVENLABS_SPEECH_ENGINE_HOST=127.0.0.1
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

Current production uses `pm2` as the `forge` user, with Nginx proxying the stable public path to the localhost bridge:

```bash
pm2 start npm --name bean-elevenlabs-voice-bridge -- run elevenlabs:voice-bridge
pm2 save
```

Nginx server include installed at `/etc/nginx/forge-conf/3062576/server/bean-elevenlabs-voice-bridge.conf`:

```nginx
location = /bean/elevenlabs/speech-engine/healthz {
    proxy_pass http://127.0.0.1:3001/healthz;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 30s;
    proxy_send_timeout 30s;
}

location /bean/elevenlabs/speech-engine/ws {
    proxy_pass http://127.0.0.1:3001/ws;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
    proxy_buffering off;
}
```

ElevenLabs Speech Engine `wsUrl` is:

```text
wss://heybean.org/bean/elevenlabs/speech-engine/ws
```

The bridge verifies ElevenLabs' signed Speech Engine JWT before accepting WebSocket sessions.

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
