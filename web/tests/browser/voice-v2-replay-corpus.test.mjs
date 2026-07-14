import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    buildReplayCases,
    MISSED_HEY_ADDRESS_CASES,
    NEGATIVE_PRIVACY_CASES,
    publicCorpusMetadata,
    REPLAY_ENGLISH_VOICES,
    STRICT_WAKE_COMMAND_CASES,
} from './voice-v2-replay-corpus.mjs';
import { withBenchmarkDeadline } from './voice-v2-benchmark-deadline.mjs';

const locales = new Map(REPLAY_ENGLISH_VOICES.map((voice) => [voice, 'en_US']));

test('wake replay matrix cross-tests every required family across all six voices', () => {
    const cases = buildReplayCases(locales);
    const expectedVoices = [...REPLAY_ENGLISH_VOICES].sort();
    assert.deepEqual(
        NEGATIVE_PRIVACY_CASES.map((entry) => entry.family).sort(),
        [
            'been_homophone',
            'green_bean',
            'hey_beam_near_miss',
            'hey_ben_near_miss',
            'ongoing_conversation_without_address',
            'pretty_girl_ambient_speech',
            'pretty_good_ambient_speech',
            'third_person_bean_at_utterance_start',
            'third_person_bean_mid_utterance',
        ],
    );
    assert.equal(MISSED_HEY_ADDRESS_CASES.length, 4);
    assert.equal(STRICT_WAKE_COMMAND_CASES.length, 1);

    assert.equal(cases.filter((entry) => entry.family === 'isolated_strict_wake').length, 6);
    assert.equal(cases.filter((entry) => entry.family === 'strict_wake_in_ongoing_speech').length, 6);
    assert.equal(cases.filter((entry) => entry.family === 'strict_wake_time_command').length, 6);

    for (const negative of NEGATIVE_PRIVACY_CASES) {
        const entries = cases.filter((entry) => entry.family === negative.family);
        assert.deepEqual(entries.map((entry) => entry.voice).sort(), expectedVoices, negative.family);
        assert.ok(entries.every((entry) => entry.expected === 'reject'), negative.family);
    }

    for (const address of MISSED_HEY_ADDRESS_CASES) {
        const family = `missed_hey_${address.family}`;
        const entries = cases.filter((entry) => entry.family === family);
        assert.deepEqual(entries.map((entry) => entry.voice).sort(), expectedVoices, family);
        assert.ok(entries.every((entry) => entry.expected === 'missed_hey_confirmation'), family);
    }

    assert.deepEqual(
        cases.filter((entry) => entry.family === 'strict_wake_in_ongoing_speech')
            .map((entry) => entry.voice)
            .sort(),
        expectedVoices,
    );
    assert.equal(new Set(cases.map((entry) => entry.id)).size, cases.length);
});

test('public corpus metadata emits no raw PCM while retaining coverage provenance', () => {
    const cases = buildReplayCases(locales).map((entry, index) => ({
        ...entry,
        sample_rate: 16_000,
        sample_count: 800 + index,
        active_end_sample: 700 + index,
        duration_ms: 50,
        active_speech_end_ms: 44,
        sha256: 'a'.repeat(64),
        pcm_s16le_base64: 'forbidden-raw-audio',
    }));
    const generated = {
        generator: 'macos_say_offline_tts',
        generator_rate_words_per_minute: 185,
        sample_rate: 16_000,
        speech_boundary: 'test boundary',
        unique_audio_files: cases.length,
        unique_strict_wake_files: 12,
        unique_isolated_strict_wake_files: 6,
        unique_ongoing_speech_strict_wake_files: 6,
        unique_strict_wake_command_files: 6,
        unique_missed_hey_files: MISSED_HEY_ADDRESS_CASES.length * 6,
        unique_negative_privacy_files: NEGATIVE_PRIVACY_CASES.length * 6,
        negative_privacy_families: NEGATIVE_PRIVACY_CASES.map((entry) => entry.family),
        corpus: cases,
    };

    const metadata = publicCorpusMetadata(generated);
    assert.equal(JSON.stringify(metadata).includes('forbidden-raw-audio'), false);
    assert.equal(JSON.stringify(metadata).includes('pcm_s16le_base64'), false);
    assert.equal(metadata.voices.length, 6);
    assert.equal(metadata.unique_negative_privacy_files, 54);
});

test('benchmark schema makes release certification and privacy claims explicit', async () => {
    const schema = JSON.parse(await readFile(
        new URL('./voice-v2-benchmark-result.schema.json', import.meta.url),
        'utf8',
    ));

    assert.equal(schema.properties.schema_version.const, '1.2.0');
    assert.equal(schema.properties.representative_release_certification.const, false);
    assert.equal(schema.properties.release_certification.properties.release_certified.const, false);
    assert.equal(
        schema.properties.release_certification.properties
            .actual_realtime_data_channel_provider_release_measured.const,
        false,
    );
    assert.equal(schema.properties.privacy.properties.get_user_media_used.const, false);
    assert.equal(schema.properties.privacy.properties.raw_audio_output_emitted.const, false);
    assert.equal(schema.properties.engines.items.properties.diagnostic_asr, undefined);
    assert.equal(schema.properties.engines.items.properties.legacy_asr_text_diagnostic, undefined);
});

test('benchmark deadline resolves completed work and clears its timer', async () => {
    let cleared = null;
    const result = await withBenchmarkDeadline(Promise.resolve('done'), {
        timeoutMs: 25,
        label: 'completed replay',
        setTimeoutFn: () => 17,
        clearTimeoutFn: (timer) => { cleared = timer; },
    });

    assert.equal(result, 'done');
    assert.equal(cleared, 17);
});

test('benchmark deadline deterministically rejects a stalled engine', async () => {
    let deadlineCallback = null;
    let cleared = null;
    const stalled = withBenchmarkDeadline(new Promise(() => {}), {
        timeoutMs: 300000,
        label: 'webkit prerecorded wake replay',
        setTimeoutFn: (callback) => {
            deadlineCallback = callback;
            return 23;
        },
        clearTimeoutFn: (timer) => { cleared = timer; },
    });
    deadlineCallback();

    await assert.rejects(stalled, /webkit prerecorded wake replay exceeded 300000 ms/);
    assert.equal(cleared, 23);
});
