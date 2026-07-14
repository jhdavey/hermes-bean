'use strict';

const TARGET_SAMPLE_RATE = 16000;
// An 80 ms batch keeps decoder message pressure bounded while preserving
// headroom under the 500 ms strict-wake p95 contract across browser engines.
const AUDIO_BATCH_SAMPLES = 1280;
const MAX_RESAMPLED_SAMPLES_PER_RENDER = 512;
const ACTIVITY_REPORT_MS = 50;
const PROCESSOR_NAME = 'hey-bean-gate';

class HeyBeanGateProcessor extends AudioWorkletProcessor {
    constructor() {
        super();

        // This worklet is an analysis sink only. It never releases microphone
        // samples through its audio output; activated provider PCM is emitted
        // by LocalWakeGate over the Realtime data channel after local wake.
        this.captureActive = false;
        this.destroyed = false;
        this.failed = false;
        this.generation = 0;
        this.sequence = 0;
        this.sourceFramesPerTargetFrame = sampleRate / TARGET_SAMPLE_RATE;
        this.sourceFramesUntilTargetFrame = this.sourceFramesPerTargetFrame;
        this.weightedSampleSum = 0;
        this.weightedFrameCount = 0;
        this.audioBatch = new Float32Array(AUDIO_BATCH_SAMPLES);
        this.audioBatchOffset = 0;
        this.activitySquareSum = 0;
        this.activityFrameCount = 0;
        this.activityReportFrames = Math.max(1, Math.round(sampleRate * ACTIVITY_REPORT_MS / 1000));
        this.port.onmessage = (event) => this.handleControlMessage(event.data);

        if (
            !Number.isFinite(this.sourceFramesPerTargetFrame)
            || this.sourceFramesPerTargetFrame <= 0
        ) {
            this.failClosed('invalid_sample_rate', 'The audio context sample rate is invalid.');
        }
    }

    handleControlMessage(message) {
        if (this.destroyed || this.failed) {
            return;
        }

        try {
            if (!message || typeof message !== 'object') {
                throw new Error('Gate control messages must be objects.');
            }

            const generation = this.parseGeneration(message.generation);
            if (generation === null) {
                throw new Error('Gate control messages require a non-negative integer generation.');
            }

            if (generation < this.generation) {
                return;
            }

            if (generation > this.generation) {
                this.generation = generation;
                this.sequence = 0;
                this.resetAudioPipeline();
                this.captureActive = false;
            }

            if (message.type === 'activate') {
                this.captureActive = true;
                return;
            }

            if (message.type === 'close') {
                this.captureActive = false;
                this.port.postMessage({
                    type: 'processor_ready',
                    generation: this.generation,
                });
                return;
            }

            if (message.type === 'destroy') {
                this.captureActive = false;
                this.destroyed = true;
                this.resetAudioPipeline();
                this.port.close();
                return;
            }

            throw new Error('The gate received an unsupported control message type.');
        } catch (error) {
            this.failClosed('invalid_control_message', error);
        }
    }

    process(inputs, outputs) {
        if (this.destroyed) {
            this.silence(outputs);
            return false;
        }

        if (this.failed) {
            this.silence(outputs);
            return true;
        }

        try {
            const inputChannels = inputs[0] || [];
            const monoInput = inputChannels[0] || null;

            if (monoInput) {
                this.validateSamples(monoInput);
                this.measureAndPostActivity(monoInput);
                this.resampleAndPost(monoInput);
            }

            // The browser must never expose raw or delayed microphone audio as
            // a MediaStream track. Keep the graph alive with bit-exact silence.
            this.silence(outputs);
        } catch (error) {
            this.failClosed('audio_processing_failed', error);
            this.silence(outputs);
        }

        return true;
    }

    validateSamples(samples) {
        for (let index = 0; index < samples.length; index += 1) {
            if (!Number.isFinite(samples[index])) {
                throw new Error('The microphone supplied a non-finite audio sample.');
            }
        }
    }

    measureAndPostActivity(samples) {
        for (let index = 0; index < samples.length; index += 1) {
            const sample = Math.max(-1, Math.min(1, samples[index]));
            this.activitySquareSum += sample * sample;
            this.activityFrameCount += 1;
        }
        if (this.activityFrameCount < this.activityReportFrames) return;

        const rms = Math.sqrt(this.activitySquareSum / this.activityFrameCount);
        // Ignore the quiet noise floor, then map conversational levels into a
        // stable UI signal without exposing any microphone samples.
        const level = Math.max(0, Math.min(1, (rms - 0.008) / 0.16));
        this.port.postMessage({
            type: 'activity',
            level,
            rms,
            generation: this.generation,
        });
        this.activitySquareSum = 0;
        this.activityFrameCount = 0;
    }

    resampleAndPost(samples) {
        let emittedThisRender = 0;
        const epsilon = 1e-9;

        for (let index = 0; index < samples.length; index += 1) {
            const sample = Math.max(-1, Math.min(1, samples[index]));
            let sourceFrameRemaining = 1;

            while (sourceFrameRemaining > epsilon) {
                const consumed = Math.min(
                    sourceFrameRemaining,
                    this.sourceFramesUntilTargetFrame,
                );

                this.weightedSampleSum += sample * consumed;
                this.weightedFrameCount += consumed;
                this.sourceFramesUntilTargetFrame -= consumed;
                sourceFrameRemaining -= consumed;

                if (this.sourceFramesUntilTargetFrame <= epsilon) {
                    if (emittedThisRender >= MAX_RESAMPLED_SAMPLES_PER_RENDER) {
                        throw new Error('The resampler exceeded its per-render work limit.');
                    }

                    this.appendTargetSample(
                        this.weightedFrameCount > 0
                            ? this.weightedSampleSum / this.weightedFrameCount
                            : 0,
                    );
                    emittedThisRender += 1;
                    this.weightedSampleSum = 0;
                    this.weightedFrameCount = 0;
                    this.sourceFramesUntilTargetFrame = this.sourceFramesPerTargetFrame;
                }
            }
        }
    }

    appendTargetSample(sample) {
        this.audioBatch[this.audioBatchOffset] = sample;
        this.audioBatchOffset += 1;

        if (this.audioBatchOffset < AUDIO_BATCH_SAMPLES) {
            return;
        }

        const samples = this.audioBatch;
        const sequence = this.sequence;
        this.audioBatch = new Float32Array(AUDIO_BATCH_SAMPLES);
        this.audioBatchOffset = 0;
        this.sequence = sequence >= Number.MAX_SAFE_INTEGER ? 0 : sequence + 1;

        this.port.postMessage(
            {
                type: 'audio',
                samples,
                sequence,
                generation: this.generation,
            },
            [samples.buffer],
        );
    }

    silence(outputs) {
        for (const outputChannels of outputs) {
            for (const output of outputChannels) {
                output.fill(0);
            }
        }
    }

    resetAudioPipeline() {
        this.audioBatch = new Float32Array(AUDIO_BATCH_SAMPLES);
        this.audioBatchOffset = 0;
        this.weightedSampleSum = 0;
        this.weightedFrameCount = 0;
        this.sourceFramesUntilTargetFrame = this.sourceFramesPerTargetFrame;
        this.activitySquareSum = 0;
        this.activityFrameCount = 0;
    }

    failClosed(code, error) {
        this.captureActive = false;
        this.failed = true;
        this.resetAudioPipeline();

        try {
            this.port.postMessage({
                type: 'error',
                code,
                message: this.safeMessage(error),
                generation: this.generation,
            });
        } catch {
            // The gate is already closed; no further recovery is safe here.
        }
    }

    parseGeneration(value) {
        return Number.isSafeInteger(value) && value >= 0 ? value : null;
    }

    safeMessage(error) {
        if (error instanceof Error) {
            return error.message.slice(0, 240);
        }

        return String(error || 'Unknown audio gate error.').slice(0, 240);
    }
}

registerProcessor(PROCESSOR_NAME, HeyBeanGateProcessor);
