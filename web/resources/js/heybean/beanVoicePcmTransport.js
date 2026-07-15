export const BEAN_VOICE_LOCAL_PCM_RATE = 16000;
export const BEAN_VOICE_REALTIME_PCM_RATE = 24000;
export const BEAN_VOICE_REALTIME_MAX_BUFFERED_BYTES = 256 * 1024;

function finiteFloat32(value) {
    if (value instanceof Float32Array) return value;
    if (ArrayBuffer.isView(value)
        && value.byteLength % Float32Array.BYTES_PER_ELEMENT === 0) {
        const copy = value.buffer.slice(value.byteOffset, value.byteOffset + value.byteLength);
        return new Float32Array(copy);
    }

    return null;
}

function pcm16Value(sample) {
    const bounded = Math.max(-1, Math.min(1, Number(sample) || 0));
    return Math.max(-32768, Math.min(32767, Math.round(
        bounded < 0 ? bounded * 32768 : bounded * 32767,
    )));
}

function defaultBase64(bytes) {
    if (typeof globalThis.btoa !== 'function') {
        throw new Error('The browser does not provide base64 audio encoding.');
    }
    let binary = '';
    for (let offset = 0; offset < bytes.length; offset += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
    }

    return globalThis.btoa(binary);
}

export class StreamingPcm16Resampler {
    constructor({
        inputSampleRate = BEAN_VOICE_LOCAL_PCM_RATE,
        outputSampleRate = BEAN_VOICE_REALTIME_PCM_RATE,
    } = {}) {
        this.inputSampleRate = Number(inputSampleRate);
        this.outputSampleRate = Number(outputSampleRate);
        if (!Number.isSafeInteger(this.inputSampleRate) || this.inputSampleRate <= 0
            || !Number.isSafeInteger(this.outputSampleRate) || this.outputSampleRate <= 0) {
            throw new Error('PCM sample rates must be positive integers.');
        }
        this.sourceStep = this.inputSampleRate / this.outputSampleRate;
        this.reset();
    }

    reset() {
        this.pending = new Float32Array(0);
        this.nextSourcePosition = 0;
    }

    append(value) {
        const samples = finiteFloat32(value);
        if (!samples || samples.length === 0) return new Uint8Array(0);
        for (let index = 0; index < samples.length; index += 1) {
            if (!Number.isFinite(samples[index])) throw new Error('PCM contains a non-finite sample.');
        }

        const combined = new Float32Array(this.pending.length + samples.length);
        combined.set(this.pending, 0);
        combined.set(samples, this.pending.length);
        const output = [];
        const epsilon = 1e-9;

        while (true) {
            const sourceIndex = Math.floor(this.nextSourcePosition + epsilon);
            const fraction = this.nextSourcePosition - sourceIndex;
            if (sourceIndex >= combined.length) break;
            if (fraction > epsilon && sourceIndex + 1 >= combined.length) break;

            const left = combined[sourceIndex];
            const sample = fraction <= epsilon
                ? left
                : left + (combined[sourceIndex + 1] - left) * fraction;
            output.push(pcm16Value(sample));
            this.nextSourcePosition += this.sourceStep;
        }

        const drop = Math.min(combined.length, Math.max(0, Math.floor(this.nextSourcePosition)));
        this.pending = combined.slice(drop);
        this.nextSourcePosition -= drop;

        const bytes = new Uint8Array(output.length * Int16Array.BYTES_PER_ELEMENT);
        const view = new DataView(bytes.buffer);
        output.forEach((sample, index) => {
            view.setInt16(index * Int16Array.BYTES_PER_ELEMENT, sample, true);
        });

        return bytes;
    }
}

export class BeanVoicePcmTransport {
    constructor({
        send,
        bufferedAmount = () => 0,
        encodeBase64 = defaultBase64,
        onAppend = () => {},
        onError = () => {},
        maxBufferedBytes = BEAN_VOICE_REALTIME_MAX_BUFFERED_BYTES,
    } = {}) {
        if (typeof send !== 'function') throw new Error('Realtime PCM transport requires an event sender.');
        this.send = send;
        this.bufferedAmount = typeof bufferedAmount === 'function' ? bufferedAmount : () => 0;
        this.encodeBase64 = typeof encodeBase64 === 'function' ? encodeBase64 : defaultBase64;
        this.onAppend = typeof onAppend === 'function' ? onAppend : () => {};
        this.onError = typeof onError === 'function' ? onError : () => {};
        this.maxBufferedBytes = Math.max(16 * 1024, Math.floor(Number(maxBufferedBytes) || 0));
        this.resampler = new StreamingPcm16Resampler();
        this.activeGeneration = null;
        this.lastSourceSequence = -1;
        this.failed = false;
    }

    activate({ generation } = {}) {
        const parsedGeneration = Number(generation);
        if (!Number.isSafeInteger(parsedGeneration) || parsedGeneration < 0) {
            throw new Error('Realtime PCM activation requires a valid generation.');
        }
        this.resampler.reset();
        this.activeGeneration = parsedGeneration;
        this.lastSourceSequence = -1;
        this.failed = false;
        if (this.send({ type: 'input_audio_buffer.clear' }) !== true) {
            return this.#fail('Realtime input was not ready to clear its input buffer.');
        }

        return true;
    }

    append({ generation, sourceSequence, sampleRate, samples, released = false } = {}) {
        if (this.failed) throw new Error('Realtime PCM transport is failed.');
        if (Number(generation) !== this.activeGeneration) return false;
        const sequence = Number(sourceSequence);
        if (!Number.isSafeInteger(sequence) || sequence < 0 || sequence <= this.lastSourceSequence) {
            return this.#fail('Activated PCM arrived out of order.');
        }
        if (Number(sampleRate) !== BEAN_VOICE_LOCAL_PCM_RATE) {
            return this.#fail('Activated PCM used an unsupported sample rate.');
        }
        const input = finiteFloat32(samples);
        if (!input || input.length === 0) return false;
        const pendingBytes = Math.max(0, Number(this.bufferedAmount()) || 0);
        if (pendingBytes > this.maxBufferedBytes) {
            return this.#fail('Realtime input could not keep up with microphone audio.');
        }

        let bytes;
        let audio;
        try {
            bytes = this.resampler.append(input);
            audio = bytes.length ? this.encodeBase64(bytes) : '';
        } catch (cause) {
            return this.#fail('Activated PCM could not be encoded.', cause);
        }
        this.lastSourceSequence = sequence;
        if (!bytes.length) return true;
        if (this.send({ type: 'input_audio_buffer.append', audio }) !== true) {
            return this.#fail('Realtime input disconnected while receiving microphone audio.');
        }
        try {
            this.onAppend(Object.freeze({
                generation: this.activeGeneration,
                sourceSequence: sequence,
                inputSamples: input.length,
                outputSamples: bytes.length / Int16Array.BYTES_PER_ELEMENT,
                bytes: bytes.length,
                released: released === true,
            }));
        } catch {
            // Diagnostics cannot own or break the live input transport.
        }

        return true;
    }

    deactivate() {
        this.activeGeneration = null;
        this.lastSourceSequence = -1;
        this.failed = false;
        this.resampler.reset();
    }

    #fail(message, cause = null) {
        this.failed = true;
        this.activeGeneration = null;
        this.resampler.reset();
        const error = new Error(message, cause ? { cause } : undefined);
        try {
            this.onError(error);
        } catch {
            // The caller still receives the terminal transport exception.
        }
        throw error;
    }
}
