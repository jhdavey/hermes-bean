import { createHash } from 'node:crypto';
import { browserVoiceV2ShellCheck } from './voice-v2-production-preflight-core.mjs';

const baseUrl = new URL(process.env.VOICE_V2_PRODUCTION_URL || 'https://heybean.org');
const timeoutMs = Math.max(1_000, Number(process.env.VOICE_V2_PREFLIGHT_TIMEOUT_MS || 15_000));
const checks = [];

async function request(path, options = {}) {
    const { responseType = 'text', ...fetchOptions } = options;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    const startedAt = performance.now();
    try {
        const response = await fetch(new URL(path, baseUrl), {
            redirect: 'follow',
            signal: controller.signal,
            ...fetchOptions,
        });
        const bytes = Buffer.from(await response.arrayBuffer());

        return {
            body: responseType === 'buffer' ? bytes : bytes.toString('utf8'),
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

async function inspectManifestAsset(id, asset) {
    const path = typeof asset?.path === 'string' ? asset.path : '';
    const expectedBytes = Number(asset?.bytes);
    const expectedSha256 = String(asset?.sha256 || '').toLowerCase();
    const expected = {
        path,
        status: 200,
        same_origin: true,
        bytes: expectedBytes,
        sha256: expectedSha256,
    };
    const metadataValid = path.startsWith('/voice/wake/')
        && Number.isSafeInteger(expectedBytes)
        && expectedBytes > 0
        && /^[a-f0-9]{64}$/.test(expectedSha256);

    if (!metadataValid) {
        return {
            id,
            pass: false,
            actual: {
                path: path || null,
                bytes: Number.isFinite(expectedBytes) ? expectedBytes : null,
                sha256: expectedSha256 || null,
                error: 'Manifest asset metadata is incomplete or invalid.',
            },
            expected: 'same-origin wake asset path with positive byte count and SHA-256',
            body: null,
        };
    }

    try {
        const response = await request(path, { responseType: 'buffer' });
        const actual = {
            path,
            status: response.status,
            same_origin: new URL(response.url).origin === baseUrl.origin,
            bytes: response.body.byteLength,
            sha256: sha256(response.body),
            duration_ms: response.durationMs,
        };

        return {
            id,
            pass: actual.status === expected.status
                && actual.same_origin
                && actual.bytes === expected.bytes
                && actual.sha256 === expected.sha256,
            actual,
            expected,
            body: response.body,
        };
    } catch (error) {
        return {
            id,
            pass: false,
            actual: {
                path,
                error: error instanceof Error ? error.message : String(error),
            },
            expected,
            body: null,
        };
    }
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
    record('wake_manifest_v17', wakeManifestResponse.status === 200
        && wakeManifest?.version === 17
        && wakeManifest?.detector === 'bundled-local-kws-proposals-with-first-party-three-class-confirmation'
        && typeof wakeManifest?.wakeModelQaCertified === 'boolean'
        && typeof wakeManifest?.releaseCertified === 'boolean'
        && wakeManifest?.localKws?.proposalAuthority === true
        && wakeManifest?.runtimeDecision?.proposalAuthority === 'bundled_local_kws'
        && JSON.stringify(wakeManifest?.runtimeDecision?.proposalAliases) === '["HEY_BEAN","BEAN"]'
        && wakeManifest?.runtimeDecision?.acceptanceAuthority === 'bean-first-party-wake-v2'
        && wakeManifest?.runtimeDecision?.proposalTailMs === 160
        && wakeManifest?.runtimeDecision?.kwsMayActivate === false
        && wakeManifest?.firstPartyWakeModel?.available === true
        && wakeManifest?.firstPartyWakeModel?.id === 'bean-first-party-wake-v2'
        && wakeManifest?.firstPartyWakeModel?.path === '/voice/wake/bean-wake-model-v2.json?v=17'
        && wakeManifest?.firstPartyWakeModel?.schemaVersion === '2.0.0'
        && wakeManifest?.firstPartyWakeModel?.architecture === 'temporal_conv1d_v1'
        && wakeManifest?.firstPartyWakeModel?.inputSamples === 21760
        && wakeManifest?.firstPartyWakeModel?.tailSamples === 2560
        && wakeManifest?.firstPartyWakeModel?.tailMs === 160
        && JSON.stringify(wakeManifest?.firstPartyWakeModel?.classes)
            === '["reject","strict_wake","missed_hey_confirmation"]', {
        status: wakeManifestResponse.status,
        version: wakeManifest?.version ?? null,
        detector: wakeManifest?.detector ?? null,
        wake_model_qa_certified: wakeManifest?.wakeModelQaCertified ?? null,
        release_certified: wakeManifest?.releaseCertified ?? null,
        proposal_authority: wakeManifest?.runtimeDecision?.proposalAuthority ?? null,
        acceptance_authority: wakeManifest?.runtimeDecision?.acceptanceAuthority ?? null,
        wake_model_available: wakeManifest?.firstPartyWakeModel?.available ?? null,
        tail_ms: wakeManifest?.firstPartyWakeModel?.tailMs ?? null,
    }, 'v17 proposal-only KWS with one available three-class model over exactly 160 ms of tail; certification fields are booleans');

    const vendorAssets = Array.isArray(wakeManifest?.vendorAssets) ? wakeManifest.vendorAssets : [];
    record('wake_vendor_assets_declared', vendorAssets.length > 0, {
        count: vendorAssets.length,
        paths: vendorAssets.map((asset) => asset?.path || null),
    }, 'one or more manifest-declared vendor wake assets');

    const manifestAssets = [
        {
            id: 'wake_worker_v17_integrity',
            asset: {
                path: wakeManifest?.worker,
                bytes: wakeManifest?.workerBytes,
                sha256: wakeManifest?.workerSha256,
            },
        },
        {
            id: 'wake_audio_worklet_v17_integrity',
            asset: {
                path: wakeManifest?.audioWorklet,
                bytes: wakeManifest?.audioWorkletBytes,
                sha256: wakeManifest?.audioWorkletSha256,
            },
        },
        {
            id: 'wake_first_party_model_v17_integrity',
            asset: wakeManifest?.firstPartyWakeModel,
        },
        ...vendorAssets.map((asset, index) => ({
            id: `wake_vendor_asset_v17_${index + 1}_integrity`,
            asset,
        })),
    ];
    const manifestAssetResults = await Promise.all(manifestAssets.map(
        ({ id, asset }) => inspectManifestAsset(id, asset),
    ));
    for (const result of manifestAssetResults) {
        record(result.id, result.pass, result.actual, result.expected);
    }

    const workerResult = manifestAssetResults[0];
    const workerSource = Buffer.isBuffer(workerResult?.body) ? workerResult.body.toString('utf8') : '';
    const proposalOnlyKws = workerSource.includes('wake_proposal')
        && workerSource.includes('requiredTailSamples');
    const singleThreeClassModel = workerSource.includes('bean-wake-model-v2.json')
        && workerSource.includes('bean-first-party-wake-v2')
        && workerSource.includes('classification_decision')
        && workerSource.includes('winningClass')
        && workerSource.includes('tailSamples')
        && workerSource.includes('PROPOSAL_CONTEXT_SAMPLES')
        && workerSource.includes('PROPOSAL_TAIL_SAMPLES')
        && workerSource.includes('TARGET_SAMPLE_RATE * 0.16')
        && workerSource.includes("'reject'")
        && workerSource.includes("'strict_wake'")
        && workerSource.includes("'missed_hey_confirmation'");
    record('wake_worker_v17', workerResult?.pass === true
        && proposalOnlyKws
        && singleThreeClassModel, {
        integrity_pass: workerResult?.pass === true,
        proposal_only_kws: proposalOnlyKws,
        single_three_class_model: singleThreeClassModel,
    }, 'integrity-verified v17 worker with proposal-only KWS and one three-class classifier over 160 ms of tail');

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
    certification: {
        wake_model_qa_certified: checks.find((check) => check.id === 'wake_manifest_v17')?.actual?.wake_model_qa_certified ?? null,
        release_certified: checks.find((check) => check.id === 'wake_manifest_v17')?.actual?.release_certified ?? null,
    },
    checks,
    limitation: 'This preflight proves development deployment presence and asset integrity only. Certification flags may remain false. It does not authenticate, request microphone access, execute work, or certify acoustic latency.',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (!report.pass) process.exitCode = 1;
