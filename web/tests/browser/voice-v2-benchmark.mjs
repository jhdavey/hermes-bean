import { execFile } from 'node:child_process';
import { existsSync } from 'node:fs';
import { writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';
import { chromium, webkit } from 'playwright';
import {
    createOfflineReplayCorpus,
    publicCorpusMetadata,
} from './voice-v2-replay-corpus.mjs';
import { withBenchmarkDeadline } from './voice-v2-benchmark-deadline.mjs';
import { startVoiceV2TestServer } from './voice-v2-static-server.mjs';

const execFileAsync = promisify(execFile);
const schemaVersion = '1.2.0';
const adapterSamples = boundedInteger(process.env.VOICE_V2_BENCHMARK_SAMPLES, 100, 10, 1_000);
const wakeReplays = boundedInteger(process.env.VOICE_V2_WAKE_REPLAYS, 4, 4, 20);
const requestedTargets = new Set(
    String(process.env.VOICE_V2_BENCHMARK_TARGETS || 'playwright-chromium,google-chrome,playwright-webkit,microsoft-edge')
        .split(',')
        .map((value) => value.trim())
        .filter(Boolean),
);
const outputPath = String(process.env.VOICE_V2_BENCHMARK_OUTPUT || '').trim();

const generatedCorpus = await createOfflineReplayCorpus();
const prerecordedReplayTimeoutMs = boundedInteger(
    process.env.VOICE_V2_PRERECORDED_TIMEOUT_MS,
    Math.max(420_000, 120_000 + (generatedCorpus.corpus.length * 4_000)),
    420_000,
    1_800_000,
);
const server = await startVoiceV2TestServer({ port: 0 });
const targets = [
    {
        id: 'playwright-chromium',
        engine: 'chromium',
        browserType: chromium,
        launch: { headless: true, args: ['--autoplay-policy=no-user-gesture-required'] },
        evidenceLabel: 'Playwright Chromium engine regression evidence',
        actualBrowserCertification: false,
    },
    {
        id: 'google-chrome',
        engine: 'chromium',
        browserType: chromium,
        launch: { headless: true, channel: 'chrome', args: ['--autoplay-policy=no-user-gesture-required'] },
        evidenceLabel: 'Installed Google Chrome product regression evidence',
        actualBrowserCertification: false,
    },
    {
        id: 'playwright-webkit',
        engine: 'webkit',
        browserType: webkit,
        launch: { headless: true },
        evidenceLabel: 'Playwright WebKit engine proxy evidence; not Apple Safari',
        proxyFor: 'Safari engine behavior only',
        actualBrowserCertification: false,
    },
    {
        id: 'microsoft-edge',
        engine: 'chromium',
        browserType: chromium,
        launch: { headless: true, channel: 'msedge', args: ['--autoplay-policy=no-user-gesture-required'] },
        evidenceLabel: 'Installed Microsoft Edge product regression evidence',
        actualBrowserCertification: false,
    },
].filter((target) => requestedTargets.has(target.id));

const report = {
    $schema: './tests/browser/voice-v2-benchmark-result.schema.json',
    schema_version: schemaVersion,
    benchmark_id: `bean-browser-voice-v2-${new Date().toISOString().replace(/[:.]/g, '-')}`,
    generated_at: new Date().toISOString(),
    classification: 'prerecorded_engine_replay_plus_synthetic_browser_adapter',
    representative_release_certification: false,
    release_certification: {},
    contract_targets: {
        wake_recognition_p95_ms_lte: 500,
        recognized_partial_to_dom_p95_ms_lte: 150,
        confirmed_barge_to_playback_stop_p95_ms_lte: 200,
        direct_write_dock_p95_ms_lte: 800,
    },
    privacy: {
        ambient_microphone_accessed: false,
        microphone_permission_requested: false,
        get_user_media_used: false,
        raw_audio_retained: false,
        raw_audio_output_emitted: false,
        replay_transport: 'offline TTS PCM injected into an in-page MediaStream; activated PCM passes through the production Realtime resampler/encoder to a non-retaining loopback sender',
    },
    host: {
        platform: os.platform(),
        release: os.release(),
        architecture: os.arch(),
        cpu: os.cpus()[0]?.model || 'unknown',
        logical_cpu_count: os.cpus().length,
        node: process.version,
        network_class: 'loopback_static_harness_no_provider_network',
    },
    installed_products: await installedProducts(),
    corpus: publicCorpusMetadata(generatedCorpus),
    requested: {
        targets: [...requestedTargets],
        adapter_samples_per_engine: adapterSamples,
        strict_wake_replays_per_unique_file: wakeReplays,
        negative_privacy_default_replays_per_unique_file: 1,
        transformed_near_match_replays_per_unique_file: 4,
        prerecorded_replay_timeout_ms: prerecordedReplayTimeoutMs,
    },
    engines: [],
    summary: {},
    limitations: [
        'Prerecorded offline TTS replay is deterministic engine regression evidence, not representative human acoustic evidence.',
        'No physical microphone, audio driver, room, background-noise condition, provider transcription, network, or audible speaker output is measured.',
        'Playwright WebKit is a Safari-engine proxy and does not certify Apple Safari.',
        'Headless installed-product runs do not replace the owner’s visible, audible Chrome or Edge deployed-development smoke.',
        'Actual Chrome, Safari, and Edge release certification still requires representative devices, human speakers, networks, and background-noise conditions.',
        'The local replay reports model decisions and provider-facing release timing separately; neither is representative release certification.',
    ],
};

try {
    for (const target of targets) {
        report.engines.push(await runTarget(target));
    }
} finally {
    await server.close();
}

const executed = report.engines.filter((entry) => !['not_installed', 'launch_unavailable'].includes(entry.status));
const failures = report.engines.filter((entry) => entry.status === 'failed');
const gatePasses = report.engines.filter((entry) => entry.prerecorded_gate?.pass === true);
const adapterPasses = report.engines.filter((entry) => entry.synthetic_adapter?.pass === true);
const engineIds = new Set(report.engines.filter((entry) => entry.status === 'passed').map((entry) => entry.id));
report.summary = {
    local_regression_pass: failures.length === 0
        && executed.length > 0
        && adapterPasses.length === executed.length
        && gatePasses.length === executed.length,
    executed_engine_count: executed.length,
    passed_real_gate_engine_count: gatePasses.length,
    passed_adapter_engine_count: adapterPasses.length,
    failed_engine_count: failures.length,
    chromium_engine_covered: engineIds.has('playwright-chromium'),
    installed_chrome_covered: engineIds.has('google-chrome'),
    webkit_proxy_covered: engineIds.has('playwright-webkit'),
    installed_edge_covered: engineIds.has('microsoft-edge'),
    actual_safari_covered: false,
    actual_edge_covered: engineIds.has('microsoft-edge'),
    representative_release_certification: false,
    certification_status: 'not_release_certified_partial_engine_regression_evidence_only',
};
report.release_certification = {
    release_certified: false,
    representative_release_certification: false,
    deterministic_local_regression_gate_pass: report.summary.local_regression_pass,
    model_accuracy_gate_pass: executed.length > 0
        && executed.every((entry) => entry.prerecorded_gate?.model_accuracy?.pass === true),
    activated_pcm_handoff_gate_pass: executed.length > 0
        && executed.every((entry) => entry.prerecorded_gate?.provider_release?.pass === true),
    local_provider_input_pipeline_measured: executed.length > 0,
    local_provider_input_pipeline_gate_pass: executed.length > 0
        && executed.every((entry) => entry.prerecorded_gate?.provider_release?.pass === true),
    actual_realtime_data_channel_provider_release_measured: false,
    representative_provider_release_gate_pass: false,
    missing_required_evidence: [
        'representative physical-microphone acoustic corpus',
        'actual Safari product run',
        'visible and audible production smoke on supported Chrome and Edge products',
        'provider transcript and audible response latency under representative networks',
        'music, nearby conversation, speaker echo, and lower-powered device samples',
    ],
};

validateReport(report);
const serialized = `${JSON.stringify(report, null, 2)}\n`;
if (outputPath) await writeFile(path.resolve(outputPath), serialized, 'utf8');
process.stdout.write(serialized);
if (!report.summary.local_regression_pass) process.exitCode = 1;

async function runTarget(target) {
    const browserConsole = [];
    const startedAt = Date.now();
    let browser = null;
    try {
        browser = await target.browserType.launch(target.launch);
    } catch (error) {
        return {
            id: target.id,
            engine: target.engine,
            evidence_label: target.evidenceLabel,
            proxy_for: target.proxyFor || null,
            actual_browser_certification: target.actualBrowserCertification,
            status: launchStatus(error),
            duration_ms: Date.now() - startedAt,
            error: normalizedError(error),
            pass: false,
        };
    }

    try {
        const context = await browser.newContext();
        const replayPage = await context.newPage();
        await installMicrophoneTripwire(replayPage);
        replayPage.setDefaultTimeout(180_000);
        replayPage.on('console', (message) => {
            if (['warning', 'error'].includes(message.type())) {
                browserConsole.push({ type: message.type(), text: message.text() });
            }
        });
        await replayPage.goto(`${server.origin}/tests/browser/fixtures/voice-v2-replay-harness.html`);
        await replayPage.evaluate(() => window.voiceReplayHarnessReady);
        await replayPage.evaluate((configuration) => {
            window.configureVoiceReplayBenchmark(configuration);
        }, {
            corpus: generatedCorpus.corpus,
            wake_replays: wakeReplays,
        });
        await replayPage.click('#run');
        const prerecordedGate = await withBenchmarkDeadline(
            replayPage.evaluate(() => window.voiceReplayRun),
            { timeoutMs: prerecordedReplayTimeoutMs, label: `${target.id} prerecorded wake replay` },
        );
        const replayMicrophoneAudit = await readMicrophoneTripwire(replayPage);
        const adapterPage = await context.newPage();
        await installMicrophoneTripwire(adapterPage);
        adapterPage.setDefaultTimeout(180_000);
        adapterPage.on('console', (message) => {
            if (['warning', 'error'].includes(message.type())) {
                browserConsole.push({ type: message.type(), text: message.text() });
            }
        });
        await adapterPage.goto(`${server.origin}/tests/browser/fixtures/voice-v2-harness.html?reset=1&autoStartPlayback=1`);
        await adapterPage.evaluate(() => window.voiceHarnessReady);
        const syntheticAdapter = await withBenchmarkDeadline(
            adapterPage.evaluate(
                (count) => window.voiceHarness.runSyntheticBenchmarks(count),
                adapterSamples,
            ),
            { timeoutMs: 180_000, label: `${target.id} synthetic adapter benchmark` },
        );
        const userAgent = await adapterPage.evaluate(() => navigator.userAgent);
        const adapterMicrophoneAudit = await readMicrophoneTripwire(adapterPage);
        await context.close();

        const microphoneAudit = {
            tripwire_installed: replayMicrophoneAudit.tripwire_installed
                && adapterMicrophoneAudit.tripwire_installed,
            get_user_media_call_count: replayMicrophoneAudit.get_user_media_call_count
                + adapterMicrophoneAudit.get_user_media_call_count,
            microphone_permission_requested: false,
            ambient_microphone_accessed: false,
        };
        const passed = prerecordedGate.pass === true
            && syntheticAdapter.pass === true
            && microphoneAudit.tripwire_installed
            && microphoneAudit.get_user_media_call_count === 0;
        return {
            id: target.id,
            engine: target.engine,
            evidence_label: target.evidenceLabel,
            proxy_for: target.proxyFor || null,
            actual_browser_certification: target.actualBrowserCertification,
            status: passed ? 'passed' : 'failed',
            product_version: browser.version(),
            user_agent: userAgent,
            headless: true,
            duration_ms: Date.now() - startedAt,
            microphone_audit: microphoneAudit,
            prerecorded_gate: prerecordedGate,
            synthetic_adapter: syntheticAdapter,
            browser_console: browserConsole,
            pass: passed,
        };
    } catch (error) {
        return {
            id: target.id,
            engine: target.engine,
            evidence_label: target.evidenceLabel,
            proxy_for: target.proxyFor || null,
            actual_browser_certification: target.actualBrowserCertification,
            status: 'failed',
            product_version: browser.version(),
            headless: true,
            duration_ms: Date.now() - startedAt,
            error: normalizedError(error),
            browser_console: browserConsole,
            pass: false,
        };
    } finally {
        await browser.close();
    }
}

function launchStatus(error) {
    const message = String(error?.message || error || '');
    return /executable doesn't exist|not found at|browser was not found|download new browsers/i.test(message)
        ? 'not_installed'
        : 'launch_unavailable';
}

async function installedProducts() {
    const products = [];
    if (process.platform === 'darwin') {
        products.push(await macProduct(
            'google-chrome',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            ['--version'],
        ));
        products.push(await macProduct(
            'microsoft-edge',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
            ['--version'],
        ));
        products.push(await safariProduct());
    }
    products.push({
        id: 'playwright-chromium',
        installed: existsSync(chromium.executablePath()),
        version: null,
        automated_by_this_run: requestedTargets.has('playwright-chromium'),
    });
    products.push({
        id: 'playwright-webkit',
        installed: existsSync(webkit.executablePath()),
        version: null,
        automated_by_this_run: requestedTargets.has('playwright-webkit'),
    });
    return products;
}

async function macProduct(id, executable, args) {
    if (!existsSync(executable)) {
        return { id, installed: false, version: null, automated_by_this_run: false };
    }
    try {
        const { stdout } = await execFileAsync(executable, args);
        return {
            id,
            installed: true,
            version: stdout.trim(),
            automated_by_this_run: requestedTargets.has(id),
        };
    } catch (error) {
        return {
            id,
            installed: true,
            version: null,
            automated_by_this_run: false,
            error: normalizedError(error),
        };
    }
}

async function safariProduct() {
    const application = '/Applications/Safari.app';
    if (!existsSync(application)) {
        return { id: 'apple-safari', installed: false, version: null, automated_by_this_run: false };
    }
    try {
        const { stdout } = await execFileAsync('/usr/bin/defaults', [
            'read',
            `${application}/Contents/Info`,
            'CFBundleShortVersionString',
        ]);
        return {
            id: 'apple-safari',
            installed: true,
            version: stdout.trim(),
            automated_by_this_run: false,
            reason_not_automated: 'This runner uses Playwright WebKit as an engine proxy; Playwright cannot automate installed Safari.',
        };
    } catch (error) {
        return {
            id: 'apple-safari',
            installed: true,
            version: null,
            automated_by_this_run: false,
            error: normalizedError(error),
        };
    }
}

async function installMicrophoneTripwire(page) {
    await page.addInitScript(() => {
        const audit = {
            tripwire_installed: false,
            media_devices_available: Boolean(navigator.mediaDevices),
            get_user_media_available: typeof navigator.mediaDevices?.getUserMedia === 'function',
            get_user_media_call_count: 0,
        };
        Object.defineProperty(globalThis, '__beanVoiceBenchmarkMicrophoneAudit', {
            value: audit,
            writable: false,
            configurable: false,
        });

        if (!audit.get_user_media_available) {
            audit.tripwire_installed = true;
            return;
        }

        const deny = () => {
            audit.get_user_media_call_count += 1;
            return Promise.reject(new DOMException(
                'The prerecorded Bean Voice benchmark forbids microphone access.',
                'NotAllowedError',
            ));
        };
        const devices = navigator.mediaDevices;
        try {
            Object.defineProperty(devices, 'getUserMedia', {
                value: deny,
                configurable: false,
                writable: false,
            });
            audit.tripwire_installed = devices.getUserMedia === deny;
        } catch {
            try {
                Object.defineProperty(Object.getPrototypeOf(devices), 'getUserMedia', {
                    value: deny,
                    configurable: false,
                    writable: false,
                });
                audit.tripwire_installed = devices.getUserMedia === deny;
            } catch {
                audit.tripwire_installed = false;
            }
        }
    });
}

async function readMicrophoneTripwire(page) {
    return page.evaluate(() => ({
        ...globalThis.__beanVoiceBenchmarkMicrophoneAudit,
    }));
}

function validateReport(value) {
    const required = [
        '$schema',
        'schema_version',
        'benchmark_id',
        'generated_at',
        'classification',
        'representative_release_certification',
        'release_certification',
        'privacy',
        'host',
        'corpus',
        'engines',
        'summary',
        'limitations',
    ];
    for (const key of required) {
        if (!Object.prototype.hasOwnProperty.call(value, key)) throw new Error(`Benchmark result is missing ${key}.`);
    }
    if (value.schema_version !== schemaVersion) throw new Error('Unexpected benchmark schema version.');
    if (!Array.isArray(value.engines) || !Array.isArray(value.limitations)) {
        throw new Error('Benchmark engines and limitations must be arrays.');
    }
    if (value.representative_release_certification !== false
        || value.privacy.ambient_microphone_accessed !== false
        || value.privacy.get_user_media_used !== false
        || value.privacy.raw_audio_retained !== false
        || value.privacy.raw_audio_output_emitted !== false
        || value.release_certification.release_certified !== false
        || value.release_certification.representative_release_certification !== false) {
        throw new Error('A local prerecorded replay may not claim representative or microphone evidence.');
    }
    for (const engine of value.engines) {
        if (engine.status === 'passed'
            && (!engine.microphone_audit
                || !engine.microphone_audit.tripwire_installed
                || engine.microphone_audit.get_user_media_call_count !== 0)) {
            throw new Error(`Benchmark target ${engine.id} did not prove zero microphone API calls.`);
        }
    }
    assertNoRawAudioPayload(value);
}

function assertNoRawAudioPayload(value, pathParts = []) {
    if (ArrayBuffer.isView(value) || value instanceof ArrayBuffer) {
        throw new Error(`Benchmark output contains a binary audio payload at ${pathParts.join('.') || '<root>'}.`);
    }
    if (!value || typeof value !== 'object') return;
    const forbiddenKeys = new Set(['pcm_s16le_base64', 'raw_pcm', 'pcm_bytes', 'audio_base64', 'audio']);
    for (const [key, child] of Object.entries(value)) {
        if (forbiddenKeys.has(key)) {
            throw new Error(`Benchmark output contains forbidden raw-audio field ${[...pathParts, key].join('.')}.`);
        }
        assertNoRawAudioPayload(child, [...pathParts, key]);
    }
}

function normalizedError(error) {
    return {
        name: String(error?.name || 'Error'),
        message: String(error?.message || error || 'Unknown benchmark error').split('\n').slice(0, 3).join('\n'),
        code: String(error?.code || ''),
    };
}

function boundedInteger(input, fallback, minimum, maximum) {
    const number = Math.floor(Number(input));
    return Number.isFinite(number) ? Math.max(minimum, Math.min(maximum, number)) : fallback;
}
