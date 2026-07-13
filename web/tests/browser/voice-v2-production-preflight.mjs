import { createHash } from 'node:crypto';
import { browserVoiceV2ShellCheck } from './voice-v2-production-preflight-core.mjs';

const baseUrl = new URL(process.env.VOICE_V2_PRODUCTION_URL || 'https://heybean.org');
const timeoutMs = Math.max(1_000, Number(process.env.VOICE_V2_PREFLIGHT_TIMEOUT_MS || 15_000));
const checks = [];

async function request(path, options = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    const startedAt = performance.now();
    try {
        const response = await fetch(new URL(path, baseUrl), {
            redirect: 'follow',
            signal: controller.signal,
            ...options,
        });
        const body = await response.text();

        return {
            body,
            durationMs: Math.round((performance.now() - startedAt) * 10) / 10,
            headers: Object.fromEntries(response.headers.entries()),
            status: response.status,
            url: response.url,
        };
    } finally {
        clearTimeout(timer);
    }
}

function record(id, pass, actual, expected) {
    checks.push({ id, pass: Boolean(pass), actual, expected });
}

function sha256(value) {
    return createHash('sha256').update(value).digest('hex');
}

try {
    const health = await request('/up');
    record('health', health.status === 200, { status: health.status, duration_ms: health.durationMs }, 'HTTP 200');

    const app = await request('/app');
    const shellCheck = browserVoiceV2ShellCheck(app.body);
    const assetPath = app.body.match(/(?:src|href)=["']([^"']*\/build\/assets\/app-[^"']+\.js)["']/i)?.[1] || null;
    record('app_shell', app.status === 200, { status: app.status, duration_ms: app.durationMs }, 'HTTP 200');
    record('v2_enabled', shellCheck.pass, shellCheck.actual, shellCheck.expected);
    record('app_asset_discovered', assetPath !== null, assetPath, 'hashed app JavaScript asset');

    if (assetPath !== null) {
        const appAsset = await request(assetPath);
        const routeMarkers = [
            '/assistant/voice/realtime/session',
            '/assistant/voice/realtime/usage',
            '/assistant/voice/speech',
            '/assistant/voice/turns',
            '/assistant/voice/state',
            '/assistant/voice/cancellations',
        ];
        const missingMarkers = routeMarkers.filter((marker) => !appAsset.body.includes(marker));
        record('v2_client_bundle', appAsset.status === 200 && missingMarkers.length === 0, {
            status: appAsset.status,
            bytes: Buffer.byteLength(appAsset.body),
            sha256: sha256(appAsset.body),
            missing_markers: missingMarkers,
        }, 'current bundle contains every Browser Voice v2 API boundary');
    }

    const wakeManifestResponse = await request('/voice/wake/manifest.json');
    let wakeManifest = null;
    try {
        wakeManifest = JSON.parse(wakeManifestResponse.body);
    } catch {
        // The recorded check below owns the failure without leaking a response body.
    }
    record('wake_manifest_v9', wakeManifestResponse.status === 200
        && wakeManifest?.version === 9
        && wakeManifest?.detector === 'bean-first-party-classifier-with-local-strict-timing-candidate'
        && wakeManifest?.wakeModelQaCertified === true, {
        status: wakeManifestResponse.status,
        version: wakeManifest?.version ?? null,
        detector: wakeManifest?.detector ?? null,
        wake_model_qa_certified: wakeManifest?.wakeModelQaCertified ?? null,
    }, 'first-party wake-model manifest version 9');

    if (typeof wakeManifest?.worker === 'string') {
        const worker = await request(wakeManifest.worker);
        record('wake_worker_v9', worker.status === 200
            && worker.body.includes('strict_wake')
            && worker.body.includes('classifyFirstPartyAddressPrefix'), {
            status: worker.status,
            bytes: Buffer.byteLength(worker.body),
            sha256: sha256(worker.body),
        }, 'current first-party wake worker');
    } else {
        record('wake_worker_v9', false, null, 'current first-party wake worker');
    }

    for (const probe of [
        { path: '/api/assistant/voice/realtime/session', method: 'POST' },
        { path: '/api/assistant/voice/realtime/usage', method: 'POST' },
        { path: '/api/assistant/voice/speech', method: 'POST' },
        { path: '/api/assistant/voice/capabilities', method: 'GET' },
        { path: '/api/assistant/voice/state?session_id=1', method: 'GET' },
    ]) {
        const response = await request(probe.path, { method: probe.method, headers: { Accept: 'application/json' } });
        record(`route:${probe.path.split('?')[0]}`, response.status === 401, response.status, 'HTTP 401 (route exists; authentication required)');
    }
} catch (error) {
    checks.push({
        id: 'preflight_runtime',
        pass: false,
        actual: error instanceof Error ? error.message : String(error),
        expected: 'all public deployment probes complete',
    });
}

const report = {
    classification: 'production_public_preflight',
    base_url: baseUrl.origin,
    observed_at: new Date().toISOString(),
    pass: checks.length > 0 && checks.every((check) => check.pass),
    checks,
    limitation: 'This preflight proves deployment presence only. It does not authenticate, request microphone access, execute work, or certify acoustic latency.',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (!report.pass) process.exitCode = 1;
