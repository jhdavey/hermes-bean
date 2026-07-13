import { LocalWakeGate } from '/resources/js/heybean/localWakeGate.js';
import { BrowserVoiceRealtimeInputTransportV2 } from '/resources/js/heybean/browserVoiceRealtimeInputV2.js';

const WAKE_TARGET_P95_MS = 500;
const STRICT_COMMAND_MIN_RELEASED_PCM_SAMPLES = 6_400;
const DEFAULT_WAKE_REPLAYS = 4;
const RESET_TIMEOUT_MS = 12_000;
let configuredRun = null;

window.configureVoiceReplayBenchmark = (input) => {
    configuredRun = structuredClone(input);
};
window.voiceReplayHarnessReady = Promise.resolve(true);

document.querySelector('#run').addEventListener('click', () => {
    const status = document.querySelector('#status');
    status.textContent = 'Running';
    const AudioContextClass = globalThis.AudioContext || globalThis.webkitAudioContext;
    let audioContext = null;
    try {
        if (typeof AudioContextClass !== 'function') throw unsupported('AudioContext is unavailable.');
        audioContext = new AudioContextClass({ latencyHint: 'interactive' });
        // This synchronous call is deliberately made inside a trusted click.
        // It keeps WebKit autoplay policy from being confused with gate support.
        const resume = audioContext.resume();
        window.voiceReplayRun = Promise.resolve(resume)
            .then(() => runPrerecordedGateBenchmark(configuredRun, audioContext))
            .then((report) => {
                status.textContent = report.pass ? 'Passed' : 'Failed';
                return report;
            })
            .catch((error) => {
                status.textContent = 'Unsupported or failed';
                return errorReport(error);
            });
    } catch (error) {
        void audioContext?.close?.();
        window.voiceReplayRun = Promise.resolve(errorReport(error));
    }
});

async function runPrerecordedGateBenchmark(input, audioContext) {
    const corpus = Array.isArray(input?.corpus) ? input.corpus : [];
    if (corpus.length === 0) throw new Error('A prerecorded replay corpus is required.');
    if (typeof audioContext.createMediaStreamDestination !== 'function') {
        throw unsupported('MediaStreamAudioDestinationNode is unavailable.');
    }

    const wakeReplays = Math.max(4, Math.min(20, Number(input?.wake_replays) || DEFAULT_WAKE_REPLAYS));
    const rawDestination = audioContext.createMediaStreamDestination();
    const silence = audioContext.createConstantSource();
    const silenceGain = audioContext.createGain();
    silence.offset.value = 0;
    silenceGain.gain.value = 0;
    silence.connect(silenceGain).connect(rawDestination);
    silence.start();

    const errors = [];
    const readyEvents = [];
    let activeTrial = null;
    let preConfirmationActivatedPcmCount = 0;
    const providerTransport = new BrowserVoiceRealtimeInputTransportV2({
        send(event) {
            // Exercise the exact clear/append event boundary without retaining
            // the base64 audio field or opening a provider/network connection.
            return event?.type === 'input_audio_buffer.clear'
                || event?.type === 'input_audio_buffer.append';
        },
        onAppend(event) {
            if (!activeTrial) return;
            activeTrial.providerAppendCount += 1;
            activeTrial.providerAppendBytes += Number(event.bytes) || 0;
            if (activeTrial.firstProviderAppendAt === null) {
                activeTrial.firstProviderAppendAt = performance.now();
                activeTrial.firstProviderAppendSourceSequence = event.sourceSequence;
                activeTrial.firstProviderAppendReleased = event.released === true;
            }
        },
        onError(error) {
            errors.push(normalizedError(error));
        },
    });
    let initialReadyResolve;
    let initialReadyReject;
    const initialReady = new Promise((resolve, reject) => {
        initialReadyResolve = resolve;
        initialReadyReject = reject;
    });
    const gate = new LocalWakeGate({
        audioContext,
        onReady(event) {
            readyEvents.push({ at_ms: round(performance.now()), generation: event.generation });
            initialReadyResolve(event);
        },
        onDetected(event) {
            if (!activeTrial) return;
            activeTrial.detections.push({
                at: performance.now(),
                activation: event.activation,
                variant: event.variant,
                sourceSequence: event.sourceSequence,
                releaseBoundary: event.releaseBoundary || null,
            });
            providerTransport.activate({ generation: event.generation });
        },
        onActivatedPcm(event) {
            if (!activeTrial) return;
            // Consume the callback synchronously and retain only timing/count
            // aggregates. Raw PCM never enters the benchmark result.
            const now = performance.now();
            let maxAbs = 0;
            for (const sample of event.samples || []) maxAbs = Math.max(maxAbs, Math.abs(sample));
            activeTrial.activatedPcmChunkCount += 1;
            activeTrial.activatedPcmSampleCount += Number(event.samples?.length) || 0;
            activeTrial.activatedPcmMaxAbs = Math.max(activeTrial.activatedPcmMaxAbs, maxAbs);
            if (activeTrial.detections.length === 0) {
                activeTrial.preConfirmationActivatedPcmCount += 1;
                preConfirmationActivatedPcmCount += 1;
                dormantMaxAbs = Math.max(dormantMaxAbs, maxAbs);
            } else if (activeTrial.firstActivatedPcmAt === null) {
                activeTrial.firstActivatedPcmAt = now;
                activeTrial.firstActivatedPcmSourceSequence = event.sourceSequence;
                activeTrial.firstActivatedPcmReleased = event.released === true;
            }
            providerTransport.append(event);
        },
        onDiagnostic(event) {
            if (!activeTrial || event?.type !== 'classification_decision') return;
            activeTrial.classifierDecisions.push({
                decision_type: event.decisionType,
                accepted: event.accepted,
                expected_class: event.expectedClass,
                winning_class: event.winningClass,
                probability: round(event.probability),
                threshold: round(event.threshold),
                sample_count: event.sampleCount,
            });
        },
        onError(error) {
            const detail = normalizedError(error);
            errors.push(detail);
            initialReadyReject(error);
        },
    });

    const startupStartedAt = performance.now();
    let dormantMaxAbs = 0;

    try {
        await gate.start(rawDestination.stream);
        await withTimeout(initialReady, 20_000, 'The local wake gate did not reach its complete readiness barrier.');
        const readyMs = performance.now() - startupStartedAt;

        const strictWakeEntries = corpus.filter((entry) => entry.family === 'isolated_strict_wake');
        const strictCommandEntries = corpus.filter((entry) => entry.family === 'strict_wake_time_command');
        const ongoingSpeechWakeEntries = corpus.filter((entry) => entry.family === 'strict_wake_in_ongoing_speech');
        const addressEntries = corpus.filter((entry) => entry.expected === 'missed_hey_confirmation');
        const negativeEntries = corpus.filter((entry) => entry.expected === 'reject');
        const strictTrials = [];
        const strictCommandTrials = [];
        const ongoingSpeechWakeTrials = [];
        const addressTrials = [];
        const negativeTrials = [];
        const rejectionResetRecovery = [];

        // Each voice first traverses every important near miss, resets after
        // each rejection, and then must accept one strict wake. This catches
        // detector degradation and stale-generation bugs that isolated trials
        // miss while making cross-voice privacy coverage explicit.
        for (const strictEntry of strictWakeEntries) {
            const voiceNegatives = negativeEntries.filter((entry) => entry.voice === strictEntry.voice);
            const rejected = [];
            for (const entry of voiceNegatives) {
                const trial = await runTrial(entry, 1, 'repeated_rejection_then_wake');
                negativeTrials.push(trial);
                rejected.push(trial);
            }
            const wake = await runTrial(strictEntry, 1, 'repeated_rejection_then_wake');
            strictTrials.push(wake);
            rejectionResetRecovery.push({
                voice: strictEntry.voice,
                rejected_trial_ids: rejected.map((trial) => trial.id),
                rejection_count: rejected.length,
                all_rejected_without_activation: rejected.every((trial) => trial.matched),
                every_reset_rearmed: [...rejected, wake].every((trial) => trial.reset_rearmed),
                recovery_wake_trial_id: wake.id,
                recovery_wake_detected: wake.matched,
                pass: rejected.length === voiceNegatives.length
                    && rejected.every((trial) => trial.matched)
                    && [...rejected, wake].every((trial) => trial.reset_rearmed)
                    && wake.matched,
            });
        }
        for (let repetition = 2; repetition <= wakeReplays; repetition += 1) {
            for (const entry of strictWakeEntries) strictTrials.push(await runTrial(entry, repetition));
        }
        for (const entry of strictCommandEntries) strictCommandTrials.push(await runTrial(entry, 1));
        for (const entry of ongoingSpeechWakeEntries) {
            ongoingSpeechWakeTrials.push(await runTrial(entry, 1, 'ongoing_speech_then_strict_wake'));
        }
        for (const entry of addressEntries) addressTrials.push(await runTrial(entry, 1));

        const wakeLatencies = strictTrials
            .filter((trial) => trial.matched)
            .map((trial) => trial.phrase_end_to_confirmation_ms);
        const wakeMetric = summarize(wakeLatencies, WAKE_TARGET_P95_MS, strictTrials.length);
        const activatedPcmReleaseLatencies = strictTrials
            .filter((trial) => trial.matched && trial.phrase_end_to_first_activated_pcm_ms !== null)
            .map((trial) => trial.phrase_end_to_first_activated_pcm_ms);
        const activatedPcmReleaseMetric = summarizeObserved(activatedPcmReleaseLatencies, strictTrials.length);
        const providerAppendLatencies = strictTrials
            .filter((trial) => trial.matched && trial.phrase_end_to_first_provider_append_ms !== null)
            .map((trial) => trial.phrase_end_to_first_provider_append_ms);
        const providerAppendMetric = summarizeObserved(providerAppendLatencies, strictTrials.length);
        const strictAccuracy = ratio(strictTrials.filter((trial) => trial.matched).length, strictTrials.length);
        const ongoingSpeechWakeAccuracy = ratio(
            ongoingSpeechWakeTrials.filter((trial) => trial.matched).length,
            ongoingSpeechWakeTrials.length,
        );
        const addressAccuracy = ratio(addressTrials.filter((trial) => trial.matched).length, addressTrials.length);
        const falseWakeCount = negativeTrials.filter((trial) => trial.detections.length > 0).length;
        const strictCommandReleasePassCount = strictCommandTrials.filter((trial) => trial.matched
            && trial.activated_pcm_sample_count >= STRICT_COMMAND_MIN_RELEASED_PCM_SAMPLES).length;
        const acceptedTrials = [...strictTrials, ...strictCommandTrials, ...ongoingSpeechWakeTrials, ...addressTrials];
        const activatedPcmReleaseCount = acceptedTrials.filter(
            (trial) => trial.matched && trial.first_activated_pcm_observed,
        ).length;
        const expectedActivatedPcmReleaseCount = acceptedTrials.filter((trial) => trial.matched).length;
        const providerAppendCount = acceptedTrials.filter(
            (trial) => trial.matched && trial.first_provider_append_observed,
        ).length;
        const resetRecoveryPassCount = rejectionResetRecovery.filter((journey) => journey.pass).length;
        const qaJourneyCount = strictTrials.length
            + strictCommandTrials.length
            + ongoingSpeechWakeTrials.length
            + addressTrials.length
            + negativeTrials.length
            + rejectionResetRecovery.length;
        const qaJourneyPassCount = strictTrials.filter((trial) => trial.matched).length
            + strictCommandReleasePassCount
            + ongoingSpeechWakeTrials.filter((trial) => trial.matched).length
            + addressTrials.filter((trial) => trial.matched).length
            + negativeTrials.filter((trial) => trial.matched).length
            + resetRecoveryPassCount;
        const voiceQaPassRate = ratio(qaJourneyPassCount, qaJourneyCount);
        const voiceQaPass = voiceQaPassRate !== null && voiceQaPassRate >= 0.95;
        const resetRecoveryPass = ratio(resetRecoveryPassCount, rejectionResetRecovery.length) >= 0.95;
        const privacyPass = preConfirmationActivatedPcmCount === 0
            && acceptedTrials.every((trial) => trial.pre_confirmation_activated_pcm_count === 0);
        const pass = voiceQaPass
            && wakeMetric.pass
            && privacyPass
            && activatedPcmReleaseCount === expectedActivatedPcmReleaseCount
            && providerAppendCount === expectedActivatedPcmReleaseCount
            && strictCommandReleasePassCount === strictCommandTrials.length
            && errors.length === 0;

        return {
            status: pass ? 'passed' : 'failed',
            classification: 'prerecorded_offline_tts_real_local_gate_engine_replay',
            representative_release_certification: false,
            ambient_microphone_accessed: false,
            get_user_media_used: false,
            audio_source: 'AudioBufferSourceNode to MediaStreamAudioDestinationNode; activated PCM observed by callback',
            pipeline: [
                'prerecorded PCM',
                'browser audio clock',
                'MediaStream',
                'LocalWakeGate',
                'AudioWorklet gate/resampler',
                'Worker',
                'packaged local wake model',
                'onActivatedPcm gate handoff',
            ],
            browser_audio_context: {
                requested_sample_rate: 16_000,
                actual_sample_rate: audioContext.sampleRate,
                base_latency_ms: Number.isFinite(audioContext.baseLatency)
                    ? round(audioContext.baseLatency * 1_000)
                    : null,
                output_latency_ms: Number.isFinite(audioContext.outputLatency)
                    ? round(audioContext.outputLatency * 1_000)
                    : null,
            },
            readiness: {
                cold_start_to_complete_barrier_ms: round(readyMs),
                event_count: readyEvents.length,
                complete: gate.isReady(),
            },
            strict_wake: {
                unique_audio_files: strictWakeEntries.length,
                repetitions_per_file: wakeReplays,
                sample_count: strictTrials.length,
                detection_accuracy: strictAccuracy,
                phrase_end_to_confirmation_ms: wakeMetric,
                trials: strictTrials,
            },
            strict_wake_in_ongoing_speech: {
                unique_audio_files: ongoingSpeechWakeEntries.length,
                sample_count: ongoingSpeechWakeTrials.length,
                detection_accuracy: ongoingSpeechWakeAccuracy,
                trials: ongoingSpeechWakeTrials,
            },
            strict_wake_command_release: {
                unique_audio_files: strictCommandEntries.length,
                sample_count: strictCommandTrials.length,
                minimum_released_pcm_samples: STRICT_COMMAND_MIN_RELEASED_PCM_SAMPLES,
                pass_count: strictCommandReleasePassCount,
                pass: strictCommandReleasePassCount === strictCommandTrials.length,
                trials: strictCommandTrials,
            },
            missed_hey_address: {
                unique_audio_files: addressEntries.length,
                unique_address_forms: new Set(addressEntries.map((entry) => entry.family)).size,
                sample_count: addressTrials.length,
                detection_accuracy: addressAccuracy,
                trials: addressTrials,
            },
            negative_privacy: {
                repetitions_per_file: 1,
                unique_audio_files: negativeEntries.length,
                unique_near_miss_families: new Set(negativeEntries.map((entry) => entry.family)).size,
                voices_per_family: Object.fromEntries(
                    [...new Set(negativeEntries.map((entry) => entry.family))].map((family) => [
                        family,
                        new Set(negativeEntries.filter((entry) => entry.family === family).map((entry) => entry.voice)).size,
                    ]),
                ),
                sample_count: negativeTrials.length,
                false_wake_count: falseWakeCount,
                false_wake_rate: ratio(falseWakeCount, negativeTrials.length),
                pre_confirmation_activated_pcm_callback_count: preConfirmationActivatedPcmCount,
                dormant_activated_pcm_max_abs: dormantMaxAbs,
                zero_activated_pcm_callbacks_before_confirmation: preConfirmationActivatedPcmCount === 0,
                trials: negativeTrials,
            },
            repeated_rejection_reset_then_wake: {
                sample_count: rejectionResetRecovery.length,
                pass_count: rejectionResetRecovery.filter((journey) => journey.pass).length,
                pass: resetRecoveryPass,
                journeys: rejectionResetRecovery,
            },
            model_accuracy: {
                qa_journey_pass_count: qaJourneyPassCount,
                qa_journey_count: qaJourneyCount,
                bean_voice_qa_pass_rate: voiceQaPassRate,
                required_qa_pass_rate: 0.95,
                strict_wake_accuracy: strictAccuracy,
                strict_wake_command_release_accuracy: ratio(
                    strictCommandReleasePassCount,
                    strictCommandTrials.length,
                ),
                strict_wake_in_ongoing_speech_accuracy: ongoingSpeechWakeAccuracy,
                missed_hey_address_accuracy: addressAccuracy,
                near_miss_false_acceptance_rate: ratio(falseWakeCount, negativeTrials.length),
                repeated_reset_recovery_rate: ratio(
                    rejectionResetRecovery.filter((journey) => journey.pass).length,
                    rejectionResetRecovery.length,
                ),
                pass: voiceQaPass,
            },
            provider_release: {
                gate_handoff_measurement: 'accepted wake phrase end to first onActivatedPcm callback',
                exact_zero_callbacks_before_local_confirmation: preConfirmationActivatedPcmCount === 0,
                observed_gate_handoff_count: activatedPcmReleaseCount,
                expected_gate_handoff_count: expectedActivatedPcmReleaseCount,
                gate_handoff_coverage: ratio(activatedPcmReleaseCount, expectedActivatedPcmReleaseCount),
                isolated_strict_wake_phrase_end_to_gate_handoff_ms: activatedPcmReleaseMetric,
                realtime_provider_append_latency: {
                    measured: true,
                    milestone: 'input_audio_buffer.append accepted by an in-page loopback sender after production resampling and base64 encoding',
                    actual_data_channel_or_provider_network: false,
                    observed_append_count: providerAppendCount,
                    expected_append_count: expectedActivatedPcmReleaseCount,
                    isolated_strict_wake_phrase_end_to_append_ms: providerAppendMetric,
                },
                end_to_end_local_provider_input_pipeline_observed: providerAppendCount
                    === expectedActivatedPcmReleaseCount,
                representative_provider_release_certified: false,
                fixed_preroll_delay_certified_by_this_benchmark: false,
                accepted_boundary_content_certified_by_this_benchmark: false,
                pass: privacyPass
                    && activatedPcmReleaseCount === expectedActivatedPcmReleaseCount
                    && providerAppendCount === expectedActivatedPcmReleaseCount,
            },
            release_certification: {
                deterministic_local_regression_gate_pass: pass,
                representative_release_certification: false,
                release_certified: false,
                evidence_tier: 'prerecorded_headless_engine_regression_only',
                missing_evidence: [
                    'physical microphone and representative human speakers',
                    'room noise, music, echo, and device audio drivers',
                    'actual provider transcript and network latency',
                    'audible response playback latency',
                    'accepted-boundary PCM sentinel verification',
                ],
            },
            errors,
            pass,
            limitations: [
                'Offline synthetic voices are prerecorded before browser execution; they are not human speakers.',
                'The source is injected directly into a MediaStream, so no physical microphone, room acoustics, or device audio driver is measured.',
                'Model-confirmation timing covers the browser audio graph, worklet, worker, and packaged local wake model.',
                'Provider-input timing reaches the production input_audio_buffer.append boundary through the real resampler/encoder and an in-page loopback sender; it excludes a real data channel, provider, network, transcription, and audible playback.',
                'The benchmark consumes activated PCM synchronously but emits no PCM and therefore cannot certify the exact accepted-audio boundary from its JSON output.',
            ],
        };
    } finally {
        activeTrial = null;
        try { silence.stop(); } catch {}
        await gate.stop();
    }

    async function runTrial(entry, repetition, journeyOverride = null) {
        await waitUntil(() => gate.isReady() && !gate.isOpen(), RESET_TIMEOUT_MS, 'Wake gate was not armed for replay.');
        const trial = {
            id: entry.id,
            voice: entry.voice,
            locale: entry.locale,
            family: entry.family,
            journey: journeyOverride || entry.journey,
            expected: entry.expected,
            repetition,
            detections: [],
            classifierDecisions: [],
            activatedPcmChunkCount: 0,
            activatedPcmSampleCount: 0,
            activatedPcmMaxAbs: 0,
            preConfirmationActivatedPcmCount: 0,
            firstActivatedPcmAt: null,
            firstActivatedPcmSourceSequence: null,
            firstActivatedPcmReleased: null,
            providerAppendCount: 0,
            providerAppendBytes: 0,
            firstProviderAppendAt: null,
            firstProviderAppendSourceSequence: null,
            firstProviderAppendReleased: null,
        };
        activeTrial = trial;

        const samples = decodePcm16(entry.pcm_s16le_base64);
        const buffer = audioContext.createBuffer(1, samples.length, entry.sample_rate);
        const channel = buffer.getChannelData(0);
        for (let index = 0; index < samples.length; index += 1) channel[index] = samples[index] / 32768;

        const source = audioContext.createBufferSource();
        source.buffer = buffer;
        source.connect(rawDestination);

        const boundarySamples = Math.max(1, Math.min(samples.length, Number(entry.active_end_sample) || samples.length));
        const boundary = audioContext.createBufferSource();
        boundary.buffer = audioContext.createBuffer(1, boundarySamples, entry.sample_rate);
        boundary.connect(rawDestination);

        let sourceEndedResolve;
        const sourceEnded = new Promise((resolve) => { sourceEndedResolve = resolve; });
        let boundaryResolve;
        const phraseEnded = new Promise((resolve) => { boundaryResolve = resolve; });
        source.onended = () => sourceEndedResolve(performance.now());
        boundary.onended = () => boundaryResolve(performance.now());

        const scheduledAt = audioContext.currentTime + 0.05;
        const sourceStartAt = performance.now() + 50;
        source.start(scheduledAt);
        boundary.start(scheduledAt);
        const phraseEndAt = await phraseEnded;

        if (entry.expected === 'reject') {
            await sourceEnded;
            await delay(1_100);
        } else {
            await waitUntil(
                () => trial.detections.length > 0,
                1_500,
                'Expected local wake confirmation was not observed.',
                { reject: false },
            );
            await sourceEnded;
            await waitUntil(
                () => trial.firstActivatedPcmAt !== null,
                500,
                'Expected activated-PCM gate handoff was not observed.',
                { reject: false },
            );
            await waitUntil(
                () => trial.firstProviderAppendAt !== null,
                500,
                'Expected Realtime input append was not observed.',
                { reject: false },
            );
        }

        const detection = trial.detections[0] || null;
        const matched = entry.expected === 'reject'
            ? detection === null
            : detection?.activation === entry.expected;
        const result = {
            id: trial.id,
            voice: trial.voice,
            locale: trial.locale,
            family: trial.family,
            journey: trial.journey,
            expected: trial.expected,
            repetition,
            matched,
            detections: trial.detections.map((item) => ({
                activation: item.activation,
                variant: item.variant,
                source_sequence: item.sourceSequence,
                release_boundary: item.releaseBoundary,
                source_start_to_confirmation_ms: round(item.at - sourceStartAt),
                phrase_end_to_confirmation_raw_ms: round(item.at - phraseEndAt),
            })),
            classifier_decisions: trial.classifierDecisions,
            phrase_end_to_confirmation_ms: detection ? round(Math.max(0, detection.at - phraseEndAt)) : null,
            first_activated_pcm_observed: trial.firstActivatedPcmAt !== null,
            first_activated_pcm_source_sequence: trial.firstActivatedPcmSourceSequence,
            first_activated_pcm_was_boundary_release: trial.firstActivatedPcmReleased,
            activated_pcm_callback_count: trial.activatedPcmChunkCount,
            activated_pcm_sample_count: trial.activatedPcmSampleCount,
            activated_pcm_max_abs: trial.activatedPcmMaxAbs,
            pre_confirmation_activated_pcm_count: trial.preConfirmationActivatedPcmCount,
            model_confirmation_to_first_activated_pcm_ms: detection && trial.firstActivatedPcmAt !== null
                ? round(Math.max(0, trial.firstActivatedPcmAt - detection.at))
                : null,
            phrase_end_to_first_activated_pcm_ms: trial.firstActivatedPcmAt !== null
                ? round(Math.max(0, trial.firstActivatedPcmAt - phraseEndAt))
                : null,
            source_start_to_first_activated_pcm_ms: trial.firstActivatedPcmAt !== null
                ? round(Math.max(0, trial.firstActivatedPcmAt - sourceStartAt))
                : null,
            first_provider_append_observed: trial.firstProviderAppendAt !== null,
            first_provider_append_source_sequence: trial.firstProviderAppendSourceSequence,
            first_provider_append_was_boundary_release: trial.firstProviderAppendReleased,
            provider_append_count: trial.providerAppendCount,
            provider_append_bytes: trial.providerAppendBytes,
            activated_pcm_to_first_provider_append_ms: trial.firstActivatedPcmAt !== null
                && trial.firstProviderAppendAt !== null
                ? round(Math.max(0, trial.firstProviderAppendAt - trial.firstActivatedPcmAt))
                : null,
            model_confirmation_to_first_provider_append_ms: detection && trial.firstProviderAppendAt !== null
                ? round(Math.max(0, trial.firstProviderAppendAt - detection.at))
                : null,
            phrase_end_to_first_provider_append_ms: trial.firstProviderAppendAt !== null
                ? round(Math.max(0, trial.firstProviderAppendAt - phraseEndAt))
                : null,
        };
        activeTrial = null;
        providerTransport.deactivate();

        const generation = gate.currentGeneration();
        if (!gate.resetAfterTurn()) throw new Error(`Wake gate failed to reset after ${entry.id}.`);
        await waitUntil(
            () => gate.currentGeneration() > generation && gate.isReady() && !gate.isOpen(),
            RESET_TIMEOUT_MS,
            `Wake gate did not re-arm after ${entry.id}.`,
        );
        result.reset_generation_before = generation;
        result.reset_generation_after = gate.currentGeneration();
        result.reset_rearmed = true;
        return result;
    }
}

function decodePcm16(value) {
    const binary = atob(String(value || ''));
    const bytes = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index += 1) bytes[index] = binary.charCodeAt(index);
    const view = new DataView(bytes.buffer);
    const samples = new Int16Array(Math.floor(bytes.byteLength / 2));
    for (let index = 0; index < samples.length; index += 1) samples[index] = view.getInt16(index * 2, true);
    return samples;
}

function summarize(values, targetP95, expectedCount) {
    const ordered = [...values].sort((left, right) => left - right);
    const percentile = (ratio) => ordered[Math.min(ordered.length - 1, Math.ceil(ratio * ordered.length) - 1)] ?? null;
    const p50 = percentile(0.5);
    const p95 = percentile(0.95);
    return {
        sample_count: ordered.length,
        expected_sample_count: expectedCount,
        p50_ms: p50 === null ? null : round(p50),
        p95_ms: p95 === null ? null : round(p95),
        max_ms: ordered.length ? round(ordered.at(-1)) : null,
        target_p95_ms_lte: targetP95,
        sufficient_sample: ordered.length >= 20,
        pass: ratio(ordered.length, expectedCount) >= 0.95 && ordered.length >= 20 && p95 <= targetP95,
    };
}

function summarizeObserved(values, expectedCount) {
    const ordered = [...values].sort((left, right) => left - right);
    const percentile = (quantile) => ordered[Math.min(ordered.length - 1, Math.ceil(quantile * ordered.length) - 1)] ?? null;
    return {
        sample_count: ordered.length,
        expected_sample_count: expectedCount,
        p50_ms: ordered.length ? round(percentile(0.5)) : null,
        p95_ms: ordered.length ? round(percentile(0.95)) : null,
        max_ms: ordered.length ? round(ordered.at(-1)) : null,
        sufficient_sample: ordered.length >= 20,
        published_contract_target: null,
        complete: ordered.length === expectedCount,
    };
}

function ratio(numerator, denominator) {
    return denominator > 0 ? Number((numerator / denominator).toFixed(6)) : null;
}

function normalizedError(error) {
    const normalized = {
        name: String(error?.name || 'Error'),
        code: String(error?.code || ''),
        message: String(error?.message || error || 'Unknown benchmark error'),
    };
    if (error?.cause && error.cause !== error) normalized.cause = normalizedError(error.cause);
    return normalized;
}

function errorReport(error) {
    const normalized = normalizedError(error);
    return {
        status: normalized.code === 'unsupported' ? 'unsupported' : 'failed',
        classification: 'prerecorded_offline_tts_real_local_gate_engine_replay',
        representative_release_certification: false,
        ambient_microphone_accessed: false,
        get_user_media_used: false,
        model_accuracy: { pass: false },
        provider_release: {
            pass: false,
            realtime_provider_append_latency: {
                measured: false,
                p50_ms: null,
                p95_ms: null,
                reason: 'The local-gate replay did not complete.',
            },
            end_to_end_local_provider_input_pipeline_observed: false,
            representative_provider_release_certified: false,
        },
        release_certification: {
            deterministic_local_regression_gate_pass: false,
            representative_release_certification: false,
            release_certified: false,
            evidence_tier: 'prerecorded_headless_engine_regression_only',
            missing_evidence: ['The local replay did not complete.'],
        },
        errors: [normalized],
        pass: false,
    };
}

function unsupported(message) {
    const error = new Error(message);
    error.code = 'unsupported';
    return error;
}

function waitUntil(predicate, timeoutMs, message, { reject = true } = {}) {
    const startedAt = performance.now();
    return new Promise((resolve, rejectPromise) => {
        const check = () => {
            if (predicate()) {
                resolve(true);
                return;
            }
            if (performance.now() - startedAt >= timeoutMs) {
                if (reject) rejectPromise(new Error(message));
                else resolve(false);
                return;
            }
            setTimeout(check, 10);
        };
        check();
    });
}

function withTimeout(promise, timeoutMs, message) {
    return Promise.race([
        promise,
        new Promise((_, reject) => setTimeout(() => reject(new Error(message)), timeoutMs)),
    ]);
}

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function round(value) {
    return Number(Number(value).toFixed(3));
}
