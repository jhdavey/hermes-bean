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
export const NEGATIVE_PRIVACY_CASES = Object.freeze([
    Object.freeze({
        family: 'hey_beam_near_miss',
        phrase: 'Hey beam.',
    }),
    Object.freeze({
        family: 'hey_ben_near_miss',
        phrase: 'Hey Ben.',
    }),
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
]);
export const MISSED_HEY_ADDRESS_CASES = Object.freeze([
    Object.freeze({ family: 'can_you', phrase: 'Bean, can you help me?' }),
    Object.freeze({ family: 'what_time', phrase: 'Bean, what time is it?' }),
    Object.freeze({ family: 'set_a_reminder', phrase: 'Bean, set a reminder for four p.m.' }),
    Object.freeze({ family: 'create_a_note', phrase: 'Bean, create a note called groceries.' }),
]);
export const STRICT_WAKE_COMMAND_CASES = Object.freeze([
    Object.freeze({ family: 'strict_wake_time_command', phrase: 'Hey Bean, what time is it?' }),
]);

/**
 * Generate a memory-only, offline TTS corpus before any browser starts.
 *
 * This intentionally uses macOS `say` rather than browser speech synthesis so
 * replay audio can be injected into the real local gate without opening a
 * microphone or routing audible output through the host speakers.
 */
export async function createOfflineReplayCorpus({ rate = 185 } = {}) {
    if (process.platform !== 'darwin') {
        throw new Error('Automatic replay-corpus generation currently requires macOS `say` and `afconvert`.');
    }

    const { stdout } = await execFileAsync('/usr/bin/say', ['-v', '?'], { maxBuffer: 2_000_000 });
    const installed = parseInstalledVoices(stdout);
    const voices = REPLAY_ENGLISH_VOICES.filter((voice) => installed.has(voice));
    if (voices.length < REPLAY_ENGLISH_VOICES.length) {
        const missing = REPLAY_ENGLISH_VOICES.filter((voice) => !installed.has(voice));
        throw new Error(`The deterministic English TTS voices are unavailable: ${missing.join(', ')}`);
    }

    const cases = buildReplayCases(installed);

    const directory = await mkdtemp(path.join(tmpdir(), 'bean-voice-v2-replay-'));
    try {
        const corpus = [];
        for (const entry of cases) {
            const aiffPath = path.join(directory, `${entry.id}.aiff`);
            const wavPath = path.join(directory, `${entry.id}.wav`);
            await execFileAsync('/usr/bin/say', [
                '-v', entry.voice,
                '-r', String(rate),
                '-o', aiffPath,
                entry.phrase,
            ]);
            await execFileAsync('/usr/bin/afconvert', [
                '-f', 'WAVE',
                '-d', `LEI16@${SAMPLE_RATE}`,
                '-c', '1',
                aiffPath,
                wavPath,
            ]);

            const wav = await readFile(wavPath);
            const decoded = decodeMonoPcm16Wave(wav);
            const bounded = boundSpeech(decoded.samples, decoded.sampleRate);
            const pcmBytes = Buffer.from(
                bounded.samples.buffer,
                bounded.samples.byteOffset,
                bounded.samples.byteLength,
            );
            corpus.push({
                ...entry,
                sample_rate: decoded.sampleRate,
                sample_count: bounded.samples.length,
                active_end_sample: bounded.activeEndSample,
                duration_ms: round((bounded.samples.length / decoded.sampleRate) * 1_000),
                active_speech_end_ms: round((bounded.activeEndSample / decoded.sampleRate) * 1_000),
                sha256: createHash('sha256').update(pcmBytes).digest('hex'),
                pcm_s16le_base64: pcmBytes.toString('base64'),
            });
        }

        return {
            generator: 'macos_say_offline_tts',
            generator_rate_words_per_minute: rate,
            sample_rate: SAMPLE_RATE,
            speech_boundary: 'last absolute PCM sample >= 64, measured on the browser audio clock',
            unique_audio_files: corpus.length,
            unique_strict_wake_files: corpus.filter((entry) => entry.expected === 'strict_wake').length,
            unique_isolated_strict_wake_files: corpus.filter((entry) => entry.family === 'isolated_strict_wake').length,
            unique_ongoing_speech_strict_wake_files: corpus.filter((entry) => entry.family === 'strict_wake_in_ongoing_speech').length,
            unique_strict_wake_command_files: corpus.filter((entry) => entry.family === 'strict_wake_time_command').length,
            unique_missed_hey_files: corpus.filter((entry) => entry.expected === 'missed_hey_confirmation').length,
            unique_negative_privacy_files: corpus.filter((entry) => entry.expected === 'reject').length,
            negative_privacy_families: NEGATIVE_PRIVACY_CASES.map((entry) => entry.family),
            corpus,
        };
    } finally {
        await rm(directory, { recursive: true, force: true });
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
        unique_missed_hey_files: generated.unique_missed_hey_files,
        unique_negative_privacy_files: generated.unique_negative_privacy_files,
        negative_privacy_families: [...generated.negative_privacy_families],
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
        })),
        ...STRICT_WAKE_COMMAND_CASES.flatMap((command) => REPLAY_ENGLISH_VOICES.map((voice) => withVoice(voice, {
            id: `strict-command-${slug(command.family)}-${slug(voice)}`,
            family: command.family,
            journey: 'strict_wake_preserves_complete_command_audio',
            phrase: command.phrase,
            expected: 'strict_wake',
        }))),
        ...REPLAY_ENGLISH_VOICES.map((voice) => withVoice(voice, {
            id: `ongoing-speech-strict-wake-${slug(voice)}`,
            family: 'strict_wake_in_ongoing_speech',
            journey: 'wake_only_privacy_then_strict_wake',
            phrase: 'We should finish the shopping list before dinner and, hey Bean.',
            expected: 'strict_wake',
        })),
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
    ];
}

function parseInstalledVoices(value) {
    const voices = new Map();
    for (const line of String(value || '').split(/\r?\n/)) {
        const match = line.match(/^(.+?)\s{2,}([a-z]{2}_[A-Z]{2})\s+#/);
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
