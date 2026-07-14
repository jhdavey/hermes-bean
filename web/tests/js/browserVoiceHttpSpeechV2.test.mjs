import assert from 'node:assert/strict';
import test from 'node:test';

import { BrowserVoiceHttpSpeechTransportV2 } from '../../resources/js/heybean/browserVoiceHttpSpeechV2.js';

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((accept, fail) => {
        resolve = accept;
        reject = fail;
    });
    return { promise, resolve, reject };
}

async function settle() {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
}

function pcmChunk(samples = [0, 8_192, -8_192, 16_384]) {
    const bytes = new Uint8Array(samples.length * 2);
    const view = new DataView(bytes.buffer);
    samples.forEach((sample, index) => view.setInt16(index * 2, sample, true));
    return bytes;
}

function streamingHarness() {
    const reads = [];
    const reader = {
        canceled: false,
        read() {
            const next = deferred();
            reads.push(next);
            return next.promise;
        },
        cancel() { this.canceled = true; return Promise.resolve(); },
    };
    const response = {
        body: { getReader: () => reader },
        headers: {
            get(name) {
                const key = String(name).toLowerCase();
                if (key === 'x-bean-audio-encoding') return 'pcm_s16le';
                if (key === 'x-bean-audio-sample-rate') return '24000';
                return null;
            },
        },
    };
    const sources = [];
    const context = {
        state: 'running',
        currentTime: 0,
        destination: {},
        createGain() {
            return { gain: { value: 1 }, connect() {}, disconnect() {} };
        },
        createBuffer(channels, length, sampleRate) {
            return {
                duration: length / sampleRate,
                copyToChannel(values) { this.values = [...values]; },
            };
        },
        createBufferSource() {
            const source = {
                buffer: null,
                onended: null,
                startedAt: null,
                stopped: false,
                connect() {},
                disconnect() {},
                start(at) { this.startedAt = at; },
                stop() { this.stopped = true; },
                end() { this.onended?.(); },
            };
            sources.push(source);
            return source;
        },
    };
    const timers = new Map();
    let timerSequence = 0;
    const base = harness({ requestAudio: () => Promise.resolve(response) });
    const transport = new BrowserVoiceHttpSpeechTransportV2({
        requestAudio: () => Promise.resolve(response),
        createAudioContext: () => context,
        timers: {
            setTimeout(callback) { const id = ++timerSequence; timers.set(id, callback); return id; },
            clearTimeout(id) { timers.delete(id); },
        },
        startupTimeoutMs: 800,
    });
    return { ...base, transport, reader, reads, sources, context, timers };
}

function audioHarness() {
    const listeners = new Map();
    const audio = {
        volume: 1,
        paused: false,
        playCalls: 0,
        addEventListener(type, listener) { listeners.set(type, listener); },
        play() { this.playCalls += 1; return Promise.resolve(); },
        pause() { this.paused = true; },
        removeAttribute() {},
        load() {},
        emit(type) { listeners.get(type)?.(); },
    };
    return audio;
}

function harness({ requestAudio = null } = {}) {
    const request = deferred();
    const requests = [];
    const audio = audioHarness();
    const revoked = [];
    const starts = [];
    const ends = [];
    const errors = [];
    const transport = new BrowserVoiceHttpSpeechTransportV2({
        requestAudio: requestAudio || ((item, options) => {
            requests.push({ item, options });
            return request.promise;
        }),
        createAudio: () => audio,
        createObjectURL: () => 'blob:bean-speech',
        revokeObjectURL: (url) => revoked.push(url),
        timers: { setTimeout: () => 1, clearTimeout: () => {} },
    });
    const item = {
        id: 'turn-1:final',
        turnId: 'turn-1',
        text: 'Done—I created the note “Meal Plans” with five recipes.',
        purpose: 'final',
    };
    const listeners = {
        onStart: () => { starts.push('started'); return true; },
        onEnd: (reason) => ends.push(reason),
        onError: (error) => errors.push(error.message),
    };
    return { transport, request, requests, audio, revoked, starts, ends, errors, item, listeners };
}

test('[BV2-SPEECH-TRANSPORT-01] exact durable text is forwarded unchanged and starts only when audio plays', async () => {
    const run = harness();
    run.transport.play(run.item, run.listeners);
    assert.equal(run.requests[0].item.text, run.item.text);
    assert.deepEqual(run.starts, []);

    run.request.resolve(new Blob(['audio'], { type: 'audio/mpeg' }));
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(run.audio.playCalls, 1);
    assert.deepEqual(run.starts, []);

    run.audio.emit('playing');
    assert.deepEqual(run.starts, ['started']);
});

test('[BV2-SPEECH-TRANSPORT-02] playback completion is delivered once and releases its object URL', async () => {
    const run = harness();
    run.transport.play(run.item, run.listeners);
    run.request.resolve(new Blob(['audio'], { type: 'audio/mpeg' }));
    await Promise.resolve();
    await Promise.resolve();
    run.audio.emit('playing');
    run.audio.emit('ended');
    run.audio.emit('ended');

    assert.deepEqual(run.ends, ['completed']);
    assert.deepEqual(run.revoked, ['blob:bean-speech']);
    assert.equal(run.transport.snapshot().current, null);
});

test('[BV2-SPEECH-TRANSPORT-03] Stop aborts a pending request and late audio can never start', async () => {
    const run = harness();
    const handle = run.transport.play(run.item, run.listeners);
    assert.equal(run.transport.stop(handle, 'button_stop'), true);
    assert.equal(run.requests[0].options.signal.aborted, true);
    run.request.resolve(new Blob(['late audio'], { type: 'audio/mpeg' }));
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(run.audio.playCalls, 0);
    assert.deepEqual(run.starts, []);
    assert.deepEqual(run.ends, []);
});

test('[BV2-SPEECH-TRANSPORT-04] a synthesis failure reports once and releases ownership', async () => {
    const run = harness();
    run.transport.play(run.item, run.listeners);
    run.request.reject(new Error('TTS unavailable'));
    await Promise.resolve();
    await Promise.resolve();

    assert.deepEqual(run.errors, ['TTS unavailable']);
    assert.equal(run.transport.snapshot().current, null);
});

test('[BV2-SPEECH-TRANSPORT-05] volume changes apply to the owned audio element', async () => {
    const run = harness();
    const handle = run.transport.play(run.item, run.listeners);
    run.request.resolve(new Blob(['audio'], { type: 'audio/mpeg' }));
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(run.transport.setVolume(handle, 0.35), true);
    assert.equal(run.audio.volume, 0.35);
});

test('[BV2-SPEECH-TRANSPORT-06] streamed PCM begins before the complete response arrives and preserves exact text ownership', async () => {
    const run = streamingHarness();
    run.transport.play(run.item, run.listeners);
    await settle();
    assert.equal(run.reads.length, 1);
    assert.deepEqual(run.starts, []);

    run.reads[0].resolve({ done: false, value: pcmChunk() });
    await settle();
    assert.deepEqual(run.starts, ['started']);
    assert.equal(run.sources.length, 1);
    assert.equal(run.reads.length, 2, 'the provider stream is still open when playback begins');
    assert.deepEqual(run.ends, []);

    run.reads[1].resolve({ done: true });
    await settle();
    assert.deepEqual(run.ends, []);
    run.sources[0].end();
    assert.deepEqual(run.ends, ['completed']);
});

test('[BV2-SPEECH-TRANSPORT-07] the start deadline is cleared by first audio and can never truncate a long response', async () => {
    const run = streamingHarness();
    run.transport.play(run.item, run.listeners);
    await settle();
    assert.equal(run.timers.size, 1);
    run.reads[0].resolve({ done: false, value: pcmChunk(new Array(24_000).fill(100)) });
    await settle();
    assert.deepEqual(run.starts, ['started']);
    assert.equal(run.timers.size, 0, 'no playback-duration timer remains after audio starts');

    run.reads[1].resolve({ done: true });
    await settle();
    run.sources[0].end();
    assert.deepEqual(run.ends, ['completed']);
    assert.deepEqual(run.errors, []);
});

test('[BV2-SPEECH-TRANSPORT-08] Stop during streamed playback aborts only speech and late chunks cannot resume it', async () => {
    const run = streamingHarness();
    const handle = run.transport.play(run.item, run.listeners);
    await settle();
    run.reads[0].resolve({ done: false, value: pcmChunk() });
    await settle();
    assert.deepEqual(run.starts, ['started']);

    assert.equal(run.transport.stop(handle, 'button_stop'), true);
    assert.equal(run.reader.canceled, true);
    assert.equal(run.sources[0].stopped, true);
    assert.deepEqual(run.ends, []);
    assert.deepEqual(run.errors, []);
});

test('[BV2-SPEECH-TRANSPORT-09] a broken stream after speech starts fails once instead of reporting a false completion', async () => {
    const run = streamingHarness();
    run.transport.play(run.item, run.listeners);
    await settle();
    run.reads[0].resolve({ done: false, value: pcmChunk() });
    await settle();
    run.reads[1].reject(new Error('stream reset'));
    await settle();

    assert.deepEqual(run.starts, ['started']);
    assert.deepEqual(run.errors, ['stream reset']);
    assert.deepEqual(run.ends, []);
    assert.equal(run.transport.snapshot().current, null);
});

test('[BV2-SPEECH-TRANSPORT-10] Bean-button priming unlocks the one reusable AudioContext before asynchronous work', async () => {
    const run = streamingHarness();
    run.context.state = 'suspended';
    let resumes = 0;
    run.context.resume = async () => { resumes += 1; run.context.state = 'running'; };

    assert.equal(run.transport.prime(), true);
    await settle();
    assert.equal(resumes, 1);
    run.transport.play(run.item, run.listeners);
    await settle();
    assert.equal(resumes, 1, 'playback reuses the context unlocked by the user gesture');
});
