import assert from 'node:assert/strict';
import test from 'node:test';

import { BrowserVoiceRealtimeSpeechTransportV2 } from '../../resources/js/heybean/browserVoiceRealtimeSpeechV2.js';

function harness() {
    const sent = [];
    const starts = [];
    const ends = [];
    const errors = [];
    const transport = new BrowserVoiceRealtimeSpeechTransportV2({
        send: (event) => { sent.push(event); return true; },
        buildRequest: (item, clientResponseId) => ({
            type: 'response.create',
            response: { instructions: item.text, metadata: { heybean_response_id: clientResponseId } },
        }),
        buildCancel: (responseId) => [
            responseId ? { type: 'response.cancel', response_id: responseId } : { type: 'response.cancel' },
            { type: 'output_audio_buffer.clear' },
        ],
        timers: { setTimeout: () => 1, clearTimeout: () => {} },
    });
    const item = { id: 'turn-1:final', turnId: 'turn-1', text: 'The answer is visible.', purpose: 'final' };
    const listeners = {
        onStart: () => { starts.push('started'); return true; },
        onEnd: (reason) => ends.push(reason),
        onError: (error) => errors.push(error.message),
    };
    return { transport, item, listeners, sent, starts, ends, errors };
}

test('[BV2-SPEECH-TRANSPORT-01] audible start is reported only by the provider audio-start event', () => {
    const run = harness();
    run.transport.play(run.item, run.listeners);
    const clientResponseId = run.sent[0].response.metadata.heybean_response_id;
    assert.deepEqual(run.starts, []);

    run.transport.handleEvent({
        type: 'response.created',
        response: { id: 'provider-response-1', metadata: { heybean_response_id: clientResponseId } },
    });
    assert.deepEqual(run.starts, []);
    run.transport.handleEvent({ type: 'output_audio_buffer.started', response_id: 'provider-response-1' });
    assert.deepEqual(run.starts, ['started']);
});

test('[BV2-SPEECH-TRANSPORT-02] response and audio completion may arrive out of order but finish once', () => {
    const run = harness();
    run.transport.play(run.item, run.listeners);
    const clientResponseId = run.sent[0].response.metadata.heybean_response_id;
    run.transport.handleEvent({
        type: 'response.created',
        response: { id: 'provider-response-1', metadata: { heybean_response_id: clientResponseId } },
    });
    run.transport.handleEvent({ type: 'output_audio_buffer.started', response_id: 'provider-response-1' });
    run.transport.handleEvent({ type: 'response.done', response: { id: 'provider-response-1', status: 'completed' } });
    assert.deepEqual(run.ends, []);
    run.transport.handleEvent({ type: 'output_audio_buffer.stopped', response_id: 'provider-response-1' });
    run.transport.handleEvent({ type: 'output_audio_buffer.stopped', response_id: 'provider-response-1' });
    assert.deepEqual(run.ends, ['completed']);
    assert.equal(run.transport.snapshot().current, null);
});

test('[BV2-SPEECH-TRANSPORT-03] Stop before response.created cancels the late provider response', () => {
    const run = harness();
    const handle = run.transport.play(run.item, run.listeners);
    const clientResponseId = run.sent[0].response.metadata.heybean_response_id;
    assert.equal(run.transport.stop(handle, 'button_stop'), true);
    run.transport.handleEvent({
        type: 'response.created',
        response: { id: 'late-provider-response', metadata: { heybean_response_id: clientResponseId } },
    });

    assert.ok(run.sent.some((event) => event.type === 'response.cancel' && event.response_id === 'late-provider-response'));
    assert.deepEqual(run.starts, []);
    assert.deepEqual(run.ends, []);
});

test('[BV2-SPEECH-TRANSPORT-04] an unrelated provider response never starts or completes current speech', () => {
    const run = harness();
    run.transport.play(run.item, run.listeners);
    run.transport.handleEvent({
        type: 'response.created',
        response: { id: 'unrelated', metadata: { heybean_response_id: 'some-other-owner' } },
    });
    run.transport.handleEvent({ type: 'output_audio_buffer.started', response_id: 'unrelated' });
    run.transport.handleEvent({ type: 'response.done', response: { id: 'unrelated', status: 'completed' } });

    assert.deepEqual(run.starts, []);
    assert.deepEqual(run.ends, []);
    assert.notEqual(run.transport.snapshot().current, null);
    assert.ok(run.sent.some((event) => event.type === 'response.cancel' && event.response_id === 'unrelated'));
    assert.ok(run.sent.some((event) => event.type === 'output_audio_buffer.clear'));
});

test('[BV2-SPEECH-TRANSPORT-06] an automatic provider response is canceled even when no app speech exists', () => {
    const run = harness();
    run.transport.handleEvent({
        type: 'response.created',
        response: { id: 'provider-auto-response', metadata: {} },
    });

    assert.ok(run.sent.some((event) => event.type === 'response.cancel'
        && event.response_id === 'provider-auto-response'));
    assert.ok(run.sent.some((event) => event.type === 'output_audio_buffer.clear'));
    assert.deepEqual(run.starts, []);
});

test('[BV2-SPEECH-TRANSPORT-05] failed TTS reports one scoped playback error and releases ownership', () => {
    const run = harness();
    run.transport.play(run.item, run.listeners);
    const clientResponseId = run.sent[0].response.metadata.heybean_response_id;
    run.transport.handleEvent({
        type: 'response.created',
        response: { id: 'provider-response-1', metadata: { heybean_response_id: clientResponseId } },
    });
    run.transport.handleEvent({
        type: 'response.done',
        response: { id: 'provider-response-1', status: 'failed', status_details: { error: { message: 'TTS unavailable' } } },
    });

    assert.deepEqual(run.errors, ['TTS unavailable']);
    assert.equal(run.transport.snapshot().current, null);
});
