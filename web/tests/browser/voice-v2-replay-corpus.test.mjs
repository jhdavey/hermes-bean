import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { withBenchmarkDeadline } from './voice-v2-benchmark-deadline.mjs';
import {
    assertNoConflictingAcousticExpectations,
    buildReplayCases,
    parseInstalledVoices,
    DISALLOWED_NEAR_MATCH_CASES,
    linearPcmDurationAndGain,
    MISSED_HEY_ADDRESS_CASES,
    NEGATIVE_PRIVACY_CASES,
    publicCorpusMetadata,
    REPLAY_ENGLISH_VOICES,
    SPECIAL_NEGATIVE_PRIVACY_CASES,
    STRICT_WAKE_ACOUSTIC_VARIANT_CASES,
    STRICT_WAKE_COMMAND_CASES,
    STRICT_WAKE_STRESS_CASES,
    STRICT_WAKE_STRESS_PCM_PROFILE,
    TRANSFORMED_NEAR_MATCH_PRIVACY_CASES,
    historicalHeldOutRegressionResample,
} from './voice-v2-replay-corpus.mjs';

test('acoustically identical QA files cannot require conflicting wake decisions', () => {
    assert.doesNotThrow(() => assertNoConflictingAcousticExpectations([
        { id: 'first', sha256: 'a'.repeat(64), expected: 'strict_wake' },
        { id: 'same-decision', sha256: 'a'.repeat(64), expected: 'strict_wake' },
        { id: 'different-audio', sha256: 'b'.repeat(64), expected: 'reject' },
    ]));
    assert.throws(
        () => assertNoConflictingAcousticExpectations([
            { id: 'positive', sha256: 'a'.repeat(64), expected: 'strict_wake' },
            { id: 'negative', sha256: 'a'.repeat(64), expected: 'reject' },
        ]),
        /Acoustically identical replay files cannot have conflicting expectations/,
    );
});

test('voice inventory parser retains parenthesized macOS voices with one locale separator', () => {
    const voices = parseInstalledVoices([
        'Daniel              en_GB    # Hello! My name is Daniel.',
        'Grandma (English (US)) en_US    # Hello! My name is Grandma.',
    ].join('\n'));

    assert.equal(voices.get('Daniel'), 'en_GB');
    assert.equal(voices.get('Grandma (English (US))'), 'en_US');
});

const locales = new Map([
    ...REPLAY_ENGLISH_VOICES.map((voice) => [voice, 'en_US']),
    ...SPECIAL_NEGATIVE_PRIVACY_CASES.map((entry) => [entry.voice, 'en_US']),
]);

test('wake replay matrix accepts approved acoustic variants and keeps disallowed near matches private', () => {
    const cases = buildReplayCases(locales);
    const expectedVoices = [...REPLAY_ENGLISH_VOICES].sort();

    assert.deepEqual(
        NEGATIVE_PRIVACY_CASES.map((entry) => entry.family).sort(),
        [
            'been_homophone',
            'brief_ambient_acknowledgement',
            'brief_filler_utterance',
            'brief_nonlexical_hesitation',
            'dog_directed_second_person_conversation',
            'green_bean',
            'hey_b_near_miss',
            'hey_bead_near_miss',
            'hey_beans_near_miss',
            'hey_being_near_miss',
            'hey_ben_near_miss',
            'hey_bing_near_miss',
            'hey_dean_near_miss',
            'hey_gene_near_miss',
            'hey_team_near_miss',
            'ongoing_conversation_without_address',
            'pretty_girl_ambient_speech',
            'pretty_good_ambient_speech',
            'third_person_bean_at_utterance_start',
            'third_person_bean_mid_utterance',
            'unaddressed_hearing_check',
        ],
    );
    assert.equal(MISSED_HEY_ADDRESS_CASES.length, 4);
    assert.equal(STRICT_WAKE_COMMAND_CASES.length, 2);
    assert.equal(STRICT_WAKE_ACOUSTIC_VARIANT_CASES.length, 2);
    assert.equal(DISALLOWED_NEAR_MATCH_CASES.length, 9);
    assert.equal(NEGATIVE_PRIVACY_CASES.length, 21);
    assert.equal(SPECIAL_NEGATIVE_PRIVACY_CASES.length, 1);
    assert.equal(TRANSFORMED_NEAR_MATCH_PRIVACY_CASES.length, 9);
    assert.equal(STRICT_WAKE_STRESS_CASES.length, 1);
    assert.equal(cases.length, 197);

    assert.equal(cases.filter((entry) => entry.family === 'isolated_strict_wake').length, 6);
    assert.equal(cases.filter((entry) => entry.family === 'strict_wake_in_ongoing_speech').length, 6);
    assert.equal(cases.filter((entry) => entry.family === 'strict_wake_time_command').length, 6);
    assert.equal(cases.filter((entry) => entry.family === 'strict_wake_hearing_check').length, 6);
    const strictCases = cases.filter((entry) => entry.expected === 'strict_wake');
    assert.equal(strictCases.length, 37);
    assert.ok(strictCases.every((entry) => entry.expected_detection_count === 1));
    assert.ok(strictCases.every((entry) => entry.expected_release_policy === 'post_address_tail'));
    assert.ok(strictCases.every((entry) => !Object.hasOwn(entry, 'expected_kws_alias')));

    const acousticVariants = cases.filter((entry) => entry.journey === 'strict_wake_acoustic_variant');
    assert.equal(acousticVariants.length, 12);
    for (const variant of STRICT_WAKE_ACOUSTIC_VARIANT_CASES) {
        const entries = acousticVariants.filter((entry) => entry.family === variant.family);
        assert.deepEqual(entries.map((entry) => entry.voice).sort(), expectedVoices, variant.family);
        assert.ok(entries.every((entry) => entry.phrase === variant.phrase), variant.family);
        assert.ok(entries.every((entry) => entry.expected === 'strict_wake'), variant.family);
        assert.ok(entries.every((entry) => entry.expected_detection_count === 1), variant.family);
        assert.ok(entries.every((entry) => entry.expected_release_policy === 'post_address_tail'), variant.family);
        assert.ok(entries.every((entry) => !Object.hasOwn(entry, 'expected_kws_alias')), variant.family);
    }
    assert.ok(acousticVariants.some((entry) => entry.phrase === 'Hey beam.'));
    assert.ok(acousticVariants.some((entry) => entry.phrase === 'Hey beam, can you hear me?'));
    assert.equal(
        cases.some((entry) => entry.expected === 'reject' && /^Hey beam[,.]/u.test(entry.phrase)),
        false,
    );

    const [stress] = STRICT_WAKE_STRESS_CASES;
    const [stressEntry] = cases.filter((entry) => entry.id === stress.id);
    assert.deepEqual(stressEntry, { ...stress, locale: 'en_US' });
    assert.equal(stressEntry.voice, 'Daniel');
    assert.equal(stressEntry.rate_words_per_minute, 145);
    assert.equal(stressEntry.pcm_duration_ratio, 1.16);
    assert.equal(stressEntry.pcm_gain, 1.25);
    assert.equal(stressEntry.expected_pcm_sample_count, 37_454);
    assert.equal(Object.hasOwn(stressEntry, 'expected_kws_alias'), false);
    assert.equal(Object.hasOwn(stressEntry, 'expected_kws_accepted'), false);

    const transformedNegatives = cases.filter(
        (entry) => entry.journey === 'wake_only_transformed_near_match_privacy',
    );
    assert.equal(transformedNegatives.length, DISALLOWED_NEAR_MATCH_CASES.length);
    for (const nearMatch of DISALLOWED_NEAR_MATCH_CASES) {
        const [entry] = transformedNegatives.filter(
            (candidate) => candidate.source_disallowed_near_match_family === nearMatch.family,
        );
        assert.ok(entry, nearMatch.family);
        assert.equal(entry.phrase, `${nearMatch.phrase.replace(/[.!?]+$/u, '')}, can you hear me?`);
        assert.equal(entry.voice, STRICT_WAKE_STRESS_PCM_PROFILE.voice);
        assert.equal(entry.rate_words_per_minute, STRICT_WAKE_STRESS_PCM_PROFILE.rate_words_per_minute);
        assert.equal(entry.pcm_duration_ratio, STRICT_WAKE_STRESS_PCM_PROFILE.pcm_duration_ratio);
        assert.equal(entry.pcm_gain, STRICT_WAKE_STRESS_PCM_PROFILE.pcm_gain);
        assert.equal(entry.expected, 'reject');
        assert.equal(entry.repetitions, 4);
        assert.equal(entry.locale, 'en_US');
    }

    for (const negative of NEGATIVE_PRIVACY_CASES) {
        const entries = cases.filter((entry) => entry.family === negative.family);
        assert.deepEqual(entries.map((entry) => entry.voice).sort(), expectedVoices, negative.family);
        assert.ok(entries.every((entry) => entry.expected === 'reject'), negative.family);
    }

    for (const nearMatch of DISALLOWED_NEAR_MATCH_CASES) {
        assert.equal(cases.filter((entry) => entry.family === nearMatch.family).length, 6);
    }

    const [knownReject] = SPECIAL_NEGATIVE_PRIVACY_CASES;
    const [knownRejectEntry] = cases.filter((entry) => entry.id === knownReject.id);
    assert.deepEqual(knownRejectEntry, { ...knownReject, locale: 'en_US' });
    assert.equal(
        cases.some((entry) => entry.family === 'isolated_strict_wake' && entry.voice === knownReject.voice),
        false,
    );

    for (const address of MISSED_HEY_ADDRESS_CASES) {
        const family = `missed_hey_${address.family}`;
        const entries = cases.filter((entry) => entry.family === family);
        assert.deepEqual(entries.map((entry) => entry.voice).sort(), expectedVoices, family);
        assert.ok(entries.every((entry) => entry.expected === 'missed_hey_confirmation'), family);
    }

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
        unique_strict_wake_files: 37,
        unique_isolated_strict_wake_files: 6,
        unique_ongoing_speech_strict_wake_files: 6,
        unique_strict_wake_command_files: STRICT_WAKE_COMMAND_CASES.length * 6,
        unique_acoustic_variant_strict_wake_files:
            STRICT_WAKE_ACOUSTIC_VARIANT_CASES.length * REPLAY_ENGLISH_VOICES.length,
        unique_strict_wake_stress_files: STRICT_WAKE_STRESS_CASES.length,
        unique_missed_hey_files: MISSED_HEY_ADDRESS_CASES.length * 6,
        unique_negative_privacy_files: NEGATIVE_PRIVACY_CASES.length * 6
            + SPECIAL_NEGATIVE_PRIVACY_CASES.length
            + TRANSFORMED_NEAR_MATCH_PRIVACY_CASES.length,
        unique_special_negative_privacy_files: SPECIAL_NEGATIVE_PRIVACY_CASES.length,
        unique_transformed_near_match_privacy_files:
            TRANSFORMED_NEAR_MATCH_PRIVACY_CASES.length,
        negative_privacy_families: [
            ...NEGATIVE_PRIVACY_CASES.map((entry) => entry.family),
            ...SPECIAL_NEGATIVE_PRIVACY_CASES.map((entry) => entry.family),
            ...TRANSFORMED_NEAR_MATCH_PRIVACY_CASES.map((entry) => entry.family),
        ],
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
        corpus: cases,
    };

    const metadata = publicCorpusMetadata(generated);
    assert.equal(JSON.stringify(metadata).includes('forbidden-raw-audio'), false);
    assert.equal(JSON.stringify(metadata).includes('pcm_s16le_base64'), false);
    assert.equal(metadata.voices.length, 7);
    assert.equal(metadata.unique_audio_files, 197);
    assert.equal(
        metadata.unique_negative_privacy_files,
        NEGATIVE_PRIVACY_CASES.length * 6
            + SPECIAL_NEGATIVE_PRIVACY_CASES.length
            + TRANSFORMED_NEAR_MATCH_PRIVACY_CASES.length,
    );
    assert.equal(metadata.negative_privacy_families.length, 31);
    assert.equal(metadata.unique_special_negative_privacy_files, 1);
    assert.deepEqual(
        metadata.disallowed_near_match_families,
        DISALLOWED_NEAR_MATCH_CASES.map((entry) => entry.family),
    );
    assert.deepEqual(metadata.special_negative_privacy_profiles, generated.special_negative_privacy_profiles);
    assert.equal(metadata.unique_acoustic_variant_strict_wake_files, 12);
    assert.equal(metadata.unique_strict_wake_stress_files, 1);
    assert.deepEqual(
        metadata.strict_wake_stress_profiles,
        generated.strict_wake_stress_profiles,
    );
    assert.equal(metadata.unique_transformed_near_match_privacy_files, 9);
    assert.deepEqual(
        metadata.transformed_near_match_privacy_profiles,
        generated.transformed_near_match_privacy_profiles,
    );
});

test('historical held-out regression variant 6 applies its deterministic 0.86 PCM resample', () => {
    const samples = Int16Array.from({ length: 2_000 }, (_, index) => (
        index % 2 === 0 ? 8_192 + (index % 97) : -8_192 - (index % 89)
    ));
    const first = historicalHeldOutRegressionResample(samples, 0.86);
    const second = historicalHeldOutRegressionResample(samples, 0.86);

    assert.equal(first.samples.length, 1_720);
    assert.equal(first.activeEndSample, 1_720);
    assert.deepEqual(first.samples, second.samples);
    assert.deepEqual(
        [...first.samples.slice(0, 12)],
        [8192, -5524, 2855, -185, -2485, 5156, -7827, 5901, -3230, 558, 2115, -4788],
    );
});

test('strict-wake stress transform bounds first, then linearly resamples duration and applies gain', () => {
    const bounded = {
        samples: Int16Array.from({ length: 500 }, (_, index) => (index * 73) - 16_000),
        activeEndSample: 430,
    };
    const transformed = linearPcmDurationAndGain(bounded, 1.16, 1.25);

    assert.equal(transformed.samples.length, 580);
    assert.equal(transformed.activeEndSample, 499);
    assert.deepEqual(
        [...transformed.samples.slice(0, 8)],
        [-20000, -19921, -19843, -19764, -19685, -19607, -19528, -19450],
    );
    assert.equal(transformed.samples.at(-1), 25_534);
});

test('real-worker harness treats KWS as proposal-only and the shared classifier as final', async () => {
    const source = await readFile(
        new URL('./fixtures/voice-v2-replay-harness.js', import.meta.url),
        'utf8',
    );

    assert.match(source, /unpairedNegativeEntries/);
    assert.match(source, /standalone_negative_privacy/);
    assert.match(source, /repeatedHardNegativeEntries/);
    assert.match(source, /repeated_transformed_near_match_privacy/);
    assert.match(source, /every_unique_audio_file_executed/);
    assert.match(source, /strict_wake_acoustic_variant/);
    assert.match(source, /strict_wake_kws_stress_positive/);
    assert.match(source, /wake_proposal/);
    assert.match(source, /finalClassifierDecisionAccepted/);
    assert.match(source, /rejectedProposalDecisionMatched/);
    assert.match(source, /trial\.wakeProposals\.length === 0\s*\? trial\.classifierDecisions\.length === 0/);
    assert.match(source, /classifierDecision\?\.proposal_type === proposal\?\.proposal_type/);
    assert.match(source, /sample_count === 21_760/);
    assert.match(source, /tail_samples === 2_560/);
    assert.match(source, /release_boundary_safe/);
    assert.match(source, /expected_release_policy: 'post_address_tail'/);
    assert.match(source, /pre_confirmation_activated_pcm_count/);
    assert.match(source, /zero_accepted_classifier_decisions/);
    assert.match(source, /zero_released_pcm_or_provider_append/);
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
