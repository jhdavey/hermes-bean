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
