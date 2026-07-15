import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const SAMPLE_RATE = 16_000;
export const REPLAY_ENGLISH_VOICES = Object.freeze([
    'Samantha',
    'Daniel',
    'Karen',
    'Moira',
    'Rishi',
    'Tessa',
]);
export const DISALLOWED_NEAR_MATCH_CASES = Object.freeze([
    Object.freeze({
        family: 'hey_ben_near_miss',
        phrase: 'Hey Ben.',
    }),
    Object.freeze({
        family: 'hey_bead_near_miss',
        phrase: 'Hey bead.',
    }),
    Object.freeze({
        family: 'hey_being_near_miss',
        phrase: 'Hey being.',
    }),
    Object.freeze({
        family: 'hey_dean_near_miss',
        phrase: 'Hey Dean.',
    }),
    Object.freeze({
        family: 'hey_gene_near_miss',
        phrase: 'Hey Gene.',
    }),
    Object.freeze({
        family: 'hey_bing_near_miss',
        phrase: 'Hey Bing.',
    }),
    Object.freeze({
        family: 'hey_beans_near_miss',
        phrase: 'Hey beans.',
    }),
    Object.freeze({
        family: 'hey_team_near_miss',
        phrase: 'Hey team.',
    }),
    Object.freeze({
        family: 'hey_b_near_miss',
        phrase: 'Hey B.',
    }),
]);
export const STRICT_WAKE_STRESS_PCM_PROFILE = Object.freeze({
    voice: 'Daniel',
    rate_words_per_minute: 145,
    pcm_duration_ratio: 1.16,
    pcm_gain: 1.25,
});
export const TRANSFORMED_NEAR_MATCH_PRIVACY_CASES = Object.freeze(
    DISALLOWED_NEAR_MATCH_CASES.map((nearMatch) => Object.freeze({
        id: `transformed-near-match-privacy-${slug(nearMatch.family)}-daniel`,
        family: `transformed_near_match_${nearMatch.family}`,
        source_disallowed_near_match_family: nearMatch.family,
        journey: 'wake_only_transformed_near_match_privacy',
        phrase: `${nearMatch.phrase.replace(/[.!?]+$/u, '')}, can you hear me?`,
        ...STRICT_WAKE_STRESS_PCM_PROFILE,
        expected: 'reject',
        repetitions: 4,
    })),
);
export const SPECIAL_NEGATIVE_PRIVACY_CASES = Object.freeze([
    Object.freeze({
        id: 'historical-held-out-reject-hey-b-grandma-us-220-resample-086',
        family: 'historical_held_out_reject_hey_b_resample_086',
        journey: 'wake_only_historical_held_out_regression_privacy',
        phrase: 'Hey B.',
        voice: 'Grandma (English (US))',
        rate_words_per_minute: 220,
        historical_held_out_variant: 6,
        historical_held_out_pcm_resample_ratio: 0.86,
        expected: 'reject',
    }),
]);
export const NEGATIVE_PRIVACY_CASES = Object.freeze([
    ...DISALLOWED_NEAR_MATCH_CASES,
    Object.freeze({
        family: 'pretty_girl_ambient_speech',
        phrase: 'Pretty girl.',
    }),
    Object.freeze({
        family: 'pretty_good_ambient_speech',
        phrase: 'Pretty good.',
    }),
    Object.freeze({
        family: 'third_person_bean_mid_utterance',
        phrase: 'I told Sarah about Bean yesterday.',
    }),
    Object.freeze({
        family: 'third_person_bean_at_utterance_start',
        phrase: 'Bean is a useful assistant.',
    }),
    Object.freeze({
        family: 'green_bean',
        phrase: 'Green bean casserole is for dinner.',
    }),
    Object.freeze({
        family: 'been_homophone',
        phrase: 'They have been waiting outside.',
    }),
    Object.freeze({
        family: 'ongoing_conversation_without_address',
        phrase: 'We should finish the shopping list before dinner.',
    }),
    Object.freeze({
        family: 'unaddressed_hearing_check',
        phrase: 'Can you hear me?',
    }),
    Object.freeze({
        family: 'dog_directed_second_person_conversation',
        phrase: 'What are you doing, goof? Are you just standing there?',
    }),
    Object.freeze({
        family: 'brief_nonlexical_hesitation',
        phrase: 'Ah.',
    }),
    Object.freeze({
        family: 'brief_filler_utterance',
        phrase: 'Um.',
    }),
    Object.freeze({
        family: 'brief_ambient_acknowledgement',
        phrase: 'Okay.',
    }),
]);
export const MISSED_HEY_ADDRESS_CASES = Object.freeze([
    Object.freeze({ family: 'can_you', phrase: 'Bean, can you help me?' }),
    Object.freeze({ family: 'what_time', phrase: 'Bean, what time is it?' }),
    Object.freeze({ family: 'set_a_reminder', phrase: 'Bean, set a reminder for four p.m.' }),
    Object.freeze({ family: 'create_a_note', phrase: 'Bean, create a note called groceries.' }),
]);
export const STRICT_WAKE_COMMAND_CASES = Object.freeze([
    Object.freeze({ family: 'strict_wake_time_command', phrase: 'Hey Bean, what time is it?' }),
    Object.freeze({ family: 'strict_wake_hearing_check', phrase: 'Hey Bean, can you hear me?' }),
]);
export const STRICT_WAKE_ACOUSTIC_VARIANT_CASES = Object.freeze([
    Object.freeze({
        family: 'strict_wake_acoustic_variant_hey_beam_phrase',
        phrase: 'Hey beam.',
    }),
    Object.freeze({
        family: 'strict_wake_acoustic_variant_hey_beam_hearing_check',
        phrase: 'Hey beam, can you hear me?',
    }),
]);
export const STRICT_WAKE_STRESS_CASES = Object.freeze([
    Object.freeze({
        id: 'strict-wake-kws-stress-hearing-check-daniel-145-resample-116-gain-125',
        family: 'strict_wake_kws_stress_hearing_check',
        journey: 'strict_wake_kws_stress_positive',
        phrase: 'Hey Bean, can you hear me?',
        ...STRICT_WAKE_STRESS_PCM_PROFILE,
        expected_pcm_sample_count: 37_454,
        expected: 'strict_wake',
        expected_detection_count: 1,
        expected_release_policy: 'post_address_tail',
    }),
]);

/**
 * Generate a memory-only, offline TTS corpus before any browser starts.
 *
 * This intentionally uses macOS `say` rather than browser speech synthesis so
 * replay audio can be injected into the real local gate without opening a
 * microphone or routing audible output through the host speakers.
 */
export async function createOfflineReplayCorpus({ rate = 185, caseIds = [] } = {}) {
    if (process.platform !== 'darwin') {
        throw new Error('Automatic replay-corpus generation currently requires macOS `say` and `afconvert`.');
    }

    const { stdout } = await execFileAsync('/usr/bin/say', ['-v', '?'], { maxBuffer: 2_000_000 });
    const installed = parseInstalledVoices(stdout);
    const requiredVoices = [...new Set([
        ...REPLAY_ENGLISH_VOICES,
        ...SPECIAL_NEGATIVE_PRIVACY_CASES.map((entry) => entry.voice),
    ])];
    const voices = requiredVoices.filter((voice) => installed.has(voice));
    if (voices.length < requiredVoices.length) {
        const missing = requiredVoices.filter((voice) => !installed.has(voice));
        throw new Error(`The deterministic English TTS voices are unavailable: ${missing.join(', ')}`);
    }

    const allCases = buildReplayCases(installed);
    const requestedCaseIds = new Set(
        Array.isArray(caseIds)
            ? caseIds.map((value) => String(value || '').trim()).filter(Boolean)
            : [],
    );
    const cases = requestedCaseIds.size === 0
        ? allCases
        : allCases.filter((entry) => requestedCaseIds.has(entry.id));
    if (requestedCaseIds.size > 0 && cases.length !== requestedCaseIds.size) {
        const matchedIds = new Set(cases.map((entry) => entry.id));
        const missing = [...requestedCaseIds].filter((id) => !matchedIds.has(id));
        throw new Error(`Unknown replay-corpus case IDs: ${missing.join(', ')}`);
    }

    const directory = await mkdtemp(path.join(tmpdir(), 'bean-voice-v2-replay-'));
    try {
        const corpus = [];
        for (const entry of cases) {
            const renderRate = Number(entry.rate_words_per_minute) || rate;
            const decoded = await renderOfflineSpeech({
                directory,
                fileStem: entry.id,
                voice: entry.voice,
                rate: renderRate,
                phrase: entry.phrase,
            });
            let bounded;
            if (Number(entry.historical_held_out_pcm_resample_ratio) > 0) {
                bounded = historicalHeldOutRegressionResample(
                    decoded.samples,
                    Number(entry.historical_held_out_pcm_resample_ratio),
                );
            } else {
                bounded = boundSpeech(decoded.samples, decoded.sampleRate);
                if (Number(entry.pcm_duration_ratio) > 0) {
                    bounded = linearPcmDurationAndGain(
                        bounded,
                        Number(entry.pcm_duration_ratio),
                        Number(entry.pcm_gain) || 1,
                    );
                }
            }
            if (Number.isSafeInteger(entry.expected_pcm_sample_count)
                && bounded.samples.length !== entry.expected_pcm_sample_count) {
                throw new Error(
                    `${entry.id} produced ${bounded.samples.length} PCM samples; `
                    + `expected ${entry.expected_pcm_sample_count}.`,
                );
            }
            const pcmBytes = Buffer.from(
                bounded.samples.buffer,
                bounded.samples.byteOffset,
                bounded.samples.byteLength,
            );
            corpus.push({
                ...entry,
                render_rate_words_per_minute: renderRate,
                sample_rate: SAMPLE_RATE,
                sample_count: bounded.samples.length,
                active_end_sample: bounded.activeEndSample,
                duration_ms: round((bounded.samples.length / SAMPLE_RATE) * 1_000),
                active_speech_end_ms: round((bounded.activeEndSample / SAMPLE_RATE) * 1_000),
                sha256: createHash('sha256').update(pcmBytes).digest('hex'),
                pcm_s16le_base64: pcmBytes.toString('base64'),
            });
        }

        assertNoConflictingAcousticExpectations(corpus);

        return {
            generator: 'macos_say_offline_tts',
            generator_rate_words_per_minute: rate,
            sample_rate: SAMPLE_RATE,
            speech_boundary: 'last absolute PCM sample >= 64, measured on the browser audio clock',
            unique_audio_files: corpus.length,
            unique_strict_wake_files: corpus.filter((entry) => entry.expected === 'strict_wake').length,
            unique_isolated_strict_wake_files: corpus.filter((entry) => entry.family === 'isolated_strict_wake').length,
            unique_ongoing_speech_strict_wake_files: corpus.filter((entry) => entry.family === 'strict_wake_in_ongoing_speech').length,
            unique_strict_wake_command_files: corpus.filter(
                (entry) => entry.journey === 'strict_wake_preserves_complete_command_audio',
            ).length,
            unique_acoustic_variant_strict_wake_files: corpus.filter(
                (entry) => entry.journey === 'strict_wake_acoustic_variant',
            ).length,
            unique_strict_wake_stress_files: corpus.filter(
                (entry) => entry.journey === 'strict_wake_kws_stress_positive',
            ).length,
            unique_missed_hey_files: corpus.filter((entry) => entry.expected === 'missed_hey_confirmation').length,
            unique_negative_privacy_files: corpus.filter((entry) => entry.expected === 'reject').length,
            unique_special_negative_privacy_files: corpus.filter(
                (entry) => entry.journey === 'wake_only_historical_held_out_regression_privacy',
            ).length,
            unique_transformed_near_match_privacy_files: corpus.filter(
                (entry) => entry.journey === 'wake_only_transformed_near_match_privacy',
            ).length,
            negative_privacy_families: [...new Set(
                corpus.filter((entry) => entry.expected === 'reject').map((entry) => entry.family),
            )],
            disallowed_near_match_families: DISALLOWED_NEAR_MATCH_CASES.map((entry) => entry.family),
            special_negative_privacy_profiles: SPECIAL_NEGATIVE_PRIVACY_CASES.map((entry) => ({
                id: entry.id,
                family: entry.family,
                voice: entry.voice,
                phrase: entry.phrase,
                rate_words_per_minute: entry.rate_words_per_minute,
                historical_held_out_variant: entry.historical_held_out_variant,
                historical_held_out_pcm_resample_ratio:
                    entry.historical_held_out_pcm_resample_ratio,
            })),
            strict_wake_stress_profiles: STRICT_WAKE_STRESS_CASES.map((entry) => ({
                id: entry.id,
                family: entry.family,
                voice: entry.voice,
                phrase: entry.phrase,
                rate_words_per_minute: entry.rate_words_per_minute,
                pcm_duration_ratio: entry.pcm_duration_ratio,
                pcm_gain: entry.pcm_gain,
                expected_pcm_sample_count: entry.expected_pcm_sample_count,
            })),
            transformed_near_match_privacy_profiles:
                TRANSFORMED_NEAR_MATCH_PRIVACY_CASES.map((entry) => ({
                    id: entry.id,
                    family: entry.family,
                    source_disallowed_near_match_family:
                        entry.source_disallowed_near_match_family,
                    voice: entry.voice,
                    phrase: entry.phrase,
                    rate_words_per_minute: entry.rate_words_per_minute,
                    pcm_duration_ratio: entry.pcm_duration_ratio,
                    pcm_gain: entry.pcm_gain,
                })),
            corpus,
        };
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
}

export function assertNoConflictingAcousticExpectations(corpus) {
    const firstByHash = new Map();
    for (const entry of corpus) {
        const hash = String(entry?.sha256 || '');
        const expected = String(entry?.expected || '');
        if (!hash || !expected) continue;
        const first = firstByHash.get(hash);
        if (first && first.expected !== expected) {
            throw new Error(
                `Acoustically identical replay files cannot have conflicting expectations: `
                + `${first.id} (${first.expected}) and ${entry.id} (${expected}).`,
            );
        }
        firstByHash.set(hash, { id: entry.id, expected });
    }
}

export function publicCorpusMetadata(generated) {
    return {
        generator: generated.generator,
        generator_rate_words_per_minute: generated.generator_rate_words_per_minute,
        sample_rate: generated.sample_rate,
        speech_boundary: generated.speech_boundary,
        unique_audio_files: generated.unique_audio_files,
        unique_strict_wake_files: generated.unique_strict_wake_files,
        unique_isolated_strict_wake_files: generated.unique_isolated_strict_wake_files,
        unique_ongoing_speech_strict_wake_files: generated.unique_ongoing_speech_strict_wake_files,
        unique_strict_wake_command_files: generated.unique_strict_wake_command_files,
        unique_acoustic_variant_strict_wake_files:
            generated.unique_acoustic_variant_strict_wake_files,
        unique_strict_wake_stress_files: generated.unique_strict_wake_stress_files,
        unique_missed_hey_files: generated.unique_missed_hey_files,
        unique_negative_privacy_files: generated.unique_negative_privacy_files,
        unique_special_negative_privacy_files: generated.unique_special_negative_privacy_files,
        unique_transformed_near_match_privacy_files:
            generated.unique_transformed_near_match_privacy_files,
        negative_privacy_families: [...generated.negative_privacy_families],
        disallowed_near_match_families: [...generated.disallowed_near_match_families],
        special_negative_privacy_profiles: generated.special_negative_privacy_profiles.map((entry) => ({
            ...entry,
        })),
        strict_wake_stress_profiles: generated.strict_wake_stress_profiles.map((entry) => ({
            ...entry,
        })),
        transformed_near_match_privacy_profiles:
            generated.transformed_near_match_privacy_profiles.map((entry) => ({
                ...entry,
            })),
        voices: [...new Set(generated.corpus.map((entry) => entry.voice))],
        entries: generated.corpus.map(({ pcm_s16le_base64: _audio, ...entry }) => entry),
    };
}

/**
 * Define the cross-voice acoustic matrix independently from audio generation so
 * structural coverage can be unit tested without opening a browser or touching
 * a microphone.
 */
export function buildReplayCases(installedVoices) {
    const localeFor = (voice) => {
        const locale = installedVoices?.get?.(voice);
        if (!locale) throw new Error(`Replay voice ${voice} is missing its locale.`);
        return locale;
    };
    const withVoice = (voice, entry) => ({
        ...entry,
        voice,
        locale: localeFor(voice),
    });

    return [
        ...REPLAY_ENGLISH_VOICES.map((voice) => withVoice(voice, {
            id: `strict-wake-${slug(voice)}`,
            family: 'isolated_strict_wake',
            journey: 'initial_wake_and_rearm_recovery',
            phrase: 'Hey Bean.',
            expected: 'strict_wake',
            expected_detection_count: 1,
            expected_release_policy: 'post_address_tail',
        })),
        ...STRICT_WAKE_COMMAND_CASES.flatMap((command) => REPLAY_ENGLISH_VOICES.map((voice) => withVoice(voice, {
            id: `strict-command-${slug(command.family)}-${slug(voice)}`,
            family: command.family,
            journey: 'strict_wake_preserves_complete_command_audio',
            phrase: command.phrase,
            expected: 'strict_wake',
            expected_detection_count: 1,
            expected_release_policy: 'post_address_tail',
        }))),
        ...REPLAY_ENGLISH_VOICES.map((voice) => withVoice(voice, {
            id: `ongoing-speech-strict-wake-${slug(voice)}`,
            family: 'strict_wake_in_ongoing_speech',
            journey: 'wake_only_privacy_then_strict_wake',
            phrase: 'We should finish the shopping list before dinner and, hey Bean.',
            expected: 'strict_wake',
            expected_detection_count: 1,
            expected_release_policy: 'post_address_tail',
        })),
        ...STRICT_WAKE_ACOUSTIC_VARIANT_CASES.flatMap((variant) => (
            REPLAY_ENGLISH_VOICES.map((voice) => withVoice(voice, {
                id: `strict-acoustic-variant-${slug(variant.family)}-${slug(voice)}`,
                family: variant.family,
                journey: 'strict_wake_acoustic_variant',
                phrase: variant.phrase,
                expected: 'strict_wake',
                expected_detection_count: 1,
                expected_release_policy: 'post_address_tail',
            }))
        )),
        ...STRICT_WAKE_STRESS_CASES.map((entry) => withVoice(entry.voice, entry)),
        ...MISSED_HEY_ADDRESS_CASES.flatMap((address) => REPLAY_ENGLISH_VOICES.map((voice) => withVoice(voice, {
            id: `missed-hey-${slug(address.family)}-${slug(voice)}`,
            family: `missed_hey_${address.family}`,
            journey: 'local_missed_hey_address_recovery',
            phrase: address.phrase,
            expected: 'missed_hey_confirmation',
        }))),
        ...NEGATIVE_PRIVACY_CASES.flatMap((negative) => REPLAY_ENGLISH_VOICES.map((voice) => withVoice(voice, {
            id: `negative-${slug(negative.family)}-${slug(voice)}`,
            family: negative.family,
            journey: 'wake_only_near_miss_privacy',
            phrase: negative.phrase,
            expected: 'reject',
        }))),
        ...TRANSFORMED_NEAR_MATCH_PRIVACY_CASES.map((entry) => withVoice(entry.voice, entry)),
        ...SPECIAL_NEGATIVE_PRIVACY_CASES.map((entry) => withVoice(entry.voice, entry)),
    ];
}

async function renderOfflineSpeech({ directory, fileStem, voice, rate, phrase }) {
    const aiffPath = path.join(directory, `${fileStem}.aiff`);
    const wavPath = path.join(directory, `${fileStem}.wav`);
    await execFileAsync('/usr/bin/say', [
        '-v', voice,
        '-r', String(rate),
        '-o', aiffPath,
        phrase,
    ]);
    await execFileAsync('/usr/bin/afconvert', [
        '-f', 'WAVE',
        '-d', `LEI16@${SAMPLE_RATE}`,
        '-c', '1',
        aiffPath,
        wavPath,
    ]);
    return decodeMonoPcm16Wave(await readFile(wavPath));
}

export function parseInstalledVoices(value) {
    const voices = new Map();
    for (const line of String(value || '').split(/\r?\n/)) {
        const match = line.match(/^(.+?)\s+([a-z]{2}_[A-Z]{2})\s+#/);
        if (match) voices.set(match[1].trim(), match[2]);
    }
    return voices;
}

function decodeMonoPcm16Wave(buffer) {
    if (buffer.toString('ascii', 0, 4) !== 'RIFF' || buffer.toString('ascii', 8, 12) !== 'WAVE') {
        throw new Error('Offline TTS did not produce a RIFF/WAVE file.');
    }

    let offset = 12;
    let format = null;
    let data = null;
    while (offset + 8 <= buffer.length) {
        const type = buffer.toString('ascii', offset, offset + 4);
        const length = buffer.readUInt32LE(offset + 4);
        const start = offset + 8;
        const end = start + length;
        if (end > buffer.length) throw new Error(`Invalid WAVE chunk length for ${type}.`);
        if (type === 'fmt ') {
            format = {
                encoding: buffer.readUInt16LE(start),
                channels: buffer.readUInt16LE(start + 2),
                sampleRate: buffer.readUInt32LE(start + 4),
                bitsPerSample: buffer.readUInt16LE(start + 14),
            };
        } else if (type === 'data') {
            data = buffer.subarray(start, end);
        }
        offset = end + (length % 2);
    }

    if (!format || !data) throw new Error('WAVE file is missing format or PCM data.');
    if (format.encoding !== 1 || format.channels !== 1 || format.bitsPerSample !== 16) {
        throw new Error('Replay corpus must be mono signed 16-bit PCM.');
    }
    if (format.sampleRate !== SAMPLE_RATE) {
        throw new Error(`Replay corpus must be ${SAMPLE_RATE} Hz; received ${format.sampleRate} Hz.`);
    }

    const sampleCount = Math.floor(data.byteLength / 2);
    const samples = new Int16Array(sampleCount);
    for (let index = 0; index < sampleCount; index += 1) {
        samples[index] = data.readInt16LE(index * 2);
    }
    return { sampleRate: format.sampleRate, samples };
}

function boundSpeech(samples, sampleRate) {
    const activityThreshold = 64;
    let firstActive = 0;
    while (firstActive < samples.length && Math.abs(samples[firstActive]) < activityThreshold) firstActive += 1;
    let lastActive = samples.length - 1;
    while (lastActive > firstActive && Math.abs(samples[lastActive]) < activityThreshold) lastActive -= 1;
    if (firstActive >= samples.length) throw new Error('Offline TTS produced a silent replay fixture.');

    const leadingPad = Math.round(sampleRate * 0.04);
    const trailingPad = Math.round(sampleRate * 0.12);
    const start = Math.max(0, firstActive - leadingPad);
    const end = Math.min(samples.length, lastActive + 1 + trailingPad);
    return {
        samples: samples.slice(start, end),
        activeEndSample: lastActive - start + 1,
    };
}

export function historicalHeldOutRegressionResample(samples, ratio) {
    const value = samples instanceof Int16Array ? samples : new Int16Array(samples);
    const frame = 160;
    const active = [];
    for (let start = 0; start < value.length; start += frame) {
        const end = Math.min(value.length, start + frame);
        let sumSquares = 0;
        for (let index = start; index < end; index += 1) {
            const sample = value[index] / 32768;
            sumSquares += sample * sample;
        }
        const rms = Math.sqrt((sumSquares / Math.max(1, end - start)) + 1e-12);
        if (rms >= 0.006) active.push(Math.floor(start / frame));
    }

    let bounded;
    if (active.length === 0) {
        bounded = value.slice();
    } else {
        const start = Math.max(0, active[0] * frame - 1_600);
        const end = Math.min(value.length, (active.at(-1) + 1) * frame + 1_920);
        bounded = value.slice(start, end);
    }

    const targetSize = Math.max(400, roundTiesToEven(bounded.length * ratio));
    const resampled = new Int16Array(targetSize);
    const maximumSourceIndex = Math.max(0, bounded.length - 1);
    for (let index = 0; index < targetSize; index += 1) {
        const sourcePosition = targetSize <= 1
            ? 0
            : Math.fround((index * maximumSourceIndex) / (targetSize - 1));
        const left = Math.floor(sourcePosition);
        const right = Math.min(maximumSourceIndex, left + 1);
        const fraction = sourcePosition - left;
        const leftValue = Math.fround((bounded[left] || 0) / 32768);
        const rightValue = Math.fround((bounded[right] || 0) / 32768);
        const interpolated = Math.fround(leftValue + ((rightValue - leftValue) * fraction));
        resampled[index] = Math.max(-32768, Math.min(
            32767,
            roundTiesToEven(interpolated * 32768),
        ));
    }

    let activeEndSample = resampled.length;
    while (activeEndSample > 1 && Math.abs(resampled[activeEndSample - 1]) < 64) {
        activeEndSample -= 1;
    }
    return { samples: resampled, activeEndSample };
}

export function linearPcmDurationAndGain(bounded, durationRatio, gain) {
    const source = bounded?.samples instanceof Int16Array
        ? bounded.samples
        : new Int16Array(bounded?.samples || []);
    if (source.length === 0) throw new Error('A non-empty bounded PCM fixture is required.');
    const targetSize = Math.max(400, Math.round(source.length * durationRatio));
    const samples = new Int16Array(targetSize);
    const maximumSourceIndex = source.length - 1;
    for (let index = 0; index < targetSize; index += 1) {
        const sourcePosition = targetSize <= 1
            ? 0
            : (index * maximumSourceIndex) / (targetSize - 1);
        const left = Math.floor(sourcePosition);
        const right = Math.min(maximumSourceIndex, left + 1);
        const fraction = sourcePosition - left;
        const interpolated = source[left] + ((source[right] - source[left]) * fraction);
        samples[index] = Math.max(-32_768, Math.min(32_767, Math.round(interpolated * gain)));
    }
    return {
        samples,
        activeEndSample: Math.min(
            targetSize,
            Math.max(1, Math.round(Number(bounded.activeEndSample) * durationRatio)),
        ),
    };
}

function roundTiesToEven(value) {
    const floor = Math.floor(value);
    const fraction = value - floor;
    if (fraction < 0.5) return floor;
    if (fraction > 0.5) return floor + 1;
    return floor % 2 === 0 ? floor : floor + 1;
}

function round(value) {
    return Number(value.toFixed(3));
}

function slug(value) {
    return String(value || '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
}
