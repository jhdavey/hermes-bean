import assert from 'node:assert/strict';
import test from 'node:test';

import {
    BEAN_VOICE_LOCAL_PCM_RATE,
    BEAN_VOICE_REALTIME_PCM_RATE,
    BeanVoicePcmTransport,
    StreamingPcm16Resampler,
} from '../../resources/js/heybean/beanVoicePcmTransport.js';

test('[BEAN-PCM-01] PCM16 conversion is clamped signed little-endian', () => {
    const resampler = new StreamingPcm16Resampler({ inputSampleRate: 1, outputSampleRate: 1 });
    const bytes = resampler.append(Float32Array.from([-1, 0, 1]));
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);

    assert.equal(bytes.length, 6);
    assert.equal(view.getInt16(0, true), -32768);
    assert.equal(view.getInt16(2, true), 0);
    assert.equal(view.getInt16(4, true), 32767);
});

test('[BEAN-PCM-02] streaming 16 kHz to 24 kHz resampling preserves phase across chunks', () => {
    const onePass = new StreamingPcm16Resampler();
    const split = new StreamingPcm16Resampler();
    const source = Float32Array.from({ length: 3200 }, (_, index) => Math.sin(index / 23) * 0.5);
    const expected = onePass.append(source);
    const first = split.append(source.subarray(0, 1600));
    const second = split.append(source.subarray(1600));
    const actual = new Uint8Array(first.length + second.length);
    actual.set(first, 0);
    actual.set(second, first.length);

    assert.equal(actual.length, expected.length);
    assert.deepEqual(actual, expected);
    assert.ok(actual.length / 2 >= Math.floor(source.length * 1.5) - 1);
});

test('[BEAN-PRIVACY-PCM-01] activation clears provider input before ordered boundary flush', () => {
    const events = [];
    const appends = [];
    const transport = new BeanVoicePcmTransport({
        send: (event) => {
            events.push(event);
            return true;
        },
        encodeBase64: (bytes) => `pcm:${bytes.length}`,
        onAppend: (event) => appends.push(event),
    });

    assert.equal(transport.activate({ generation: 7 }), true);
    assert.equal(transport.append({
        generation: 7,
        sourceSequence: 42,
        sampleRate: BEAN_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.25),
        released: true,
    }), true);
    assert.equal(transport.append({
        generation: 7,
        sourceSequence: 43,
        sampleRate: BEAN_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600).fill(0.1),
    }), true);

    assert.deepEqual(events.map((event) => event.type), [
        'input_audio_buffer.clear',
        'input_audio_buffer.append',
        'input_audio_buffer.append',
    ]);
    assert.match(events[1].audio, /^pcm:/);
    assert.deepEqual(appends.map(({ sourceSequence, released }) => ({ sourceSequence, released })), [
        { sourceSequence: 42, released: true },
        { sourceSequence: 43, released: false },
    ]);
    assert.ok(appends.every((event) => event.outputSamples > 0 && event.bytes > 0));
    assert.equal(BEAN_VOICE_REALTIME_PCM_RATE, 24000);
});

test('[BEAN-PRIVACY-PCM-02] dormant or stale PCM can never cross an inactive generation', () => {
    const events = [];
    const transport = new BeanVoicePcmTransport({
        send: (event) => {
            events.push(event);
            return true;
        },
        encodeBase64: () => 'audio',
    });

    assert.equal(transport.append({
        generation: 1,
        sourceSequence: 0,
        sampleRate: BEAN_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600),
    }), false);
    transport.activate({ generation: 2 });
    assert.equal(transport.append({
        generation: 1,
        sourceSequence: 0,
        sampleRate: BEAN_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600),
    }), false);
    assert.deepEqual(events, [{ type: 'input_audio_buffer.clear' }]);
});

test('[BEAN-PCM-FAIL-01] backpressure fails closed without queuing raw audio', () => {
    const events = [];
    const errors = [];
    const transport = new BeanVoicePcmTransport({
        send: (event) => {
            events.push(event);
            return true;
        },
        bufferedAmount: () => 64 * 1024,
        maxBufferedBytes: 16 * 1024,
        onError: (error) => errors.push(error),
    });
    transport.activate({ generation: 3 });

    assert.throws(() => transport.append({
        generation: 3,
        sourceSequence: 1,
        sampleRate: BEAN_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600),
    }), /could not keep up/);
    assert.deepEqual(events, [{ type: 'input_audio_buffer.clear' }]);
    assert.equal(errors.length, 1);
});

test('[BEAN-PCM-FAIL-02] source ordering and clear delivery are terminal fail-closed boundaries', () => {
    const clearFailure = new BeanVoicePcmTransport({ send: () => false });
    assert.throws(
        () => clearFailure.activate({ generation: 1 }),
        /not ready to clear/,
    );

    const transport = new BeanVoicePcmTransport({
        send: () => true,
        encodeBase64: () => 'audio',
    });
    transport.activate({ generation: 9 });
    transport.append({
        generation: 9,
        sourceSequence: 4,
        sampleRate: BEAN_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600),
    });
    assert.throws(() => transport.append({
        generation: 9,
        sourceSequence: 4,
        sampleRate: BEAN_VOICE_LOCAL_PCM_RATE,
        samples: new Float32Array(1600),
    }), /out of order/);
});
