import assert from 'node:assert/strict';
import test from 'node:test';

import {
    acquireRealtimeMicrophone,
    createVoiceClientFailureNonce,
    realtimeMicrophoneConstraints,
    sanitizedVoiceClientFailure,
    VoiceClientFailureReporter,
    voiceClientFailureId,
    voiceClientFailureIdentityParts,
} from '../../resources/js/heybean/beanVoiceBrowserSupport.js';

globalThis.window ??= { matchMedia: () => null };
const {
    captureHeyBeanChatControlFocus,
    reconcileAllDayEndDateInput,
    restoreHeyBeanChatControlFocus,
} = await import('../../resources/js/heybean/webApp.js');

class MemoryStorage {
    constructor(entries = []) {
        this.values = new Map(entries);
    }

    getItem(key) {
        return this.values.has(key) ? this.values.get(key) : null;
    }

    setItem(key, value) {
        this.values.set(String(key), String(value));
    }

    removeItem(key) {
        this.values.delete(String(key));
    }
}

class ThrowingStorage extends MemoryStorage {
    setItem() {
        throw Object.assign(new Error('Quota exceeded'), { name: 'QuotaExceededError' });
    }
}

test('all-day start edits preserve an independently valid literal end boundary', () => {
    assert.equal(
        reconcileAllDayEndDateInput('2026-07-15', '2026-07-19'),
        '2026-07-19',
    );
});

test('all-day start edits synthesize only an empty end boundary', () => {
    assert.equal(reconcileAllDayEndDateInput('2026-07-15', ''), '2026-07-16');
    assert.equal(
        reconcileAllDayEndDateInput('2026-07-15', '2026-07-15'),
        '2026-07-15',
    );
    assert.equal(
        reconcileAllDayEndDateInput('2026-07-15', '2026-02-30'),
        '2026-02-30',
    );
    assert.equal(
        reconcileAllDayEndDateInput('2028-02-29', '2028-02-28'),
        '2028-02-28',
    );
    assert.equal(reconcileAllDayEndDateInput('not-a-date', ''), '');
});
test('[BEAN-STARTUP-04] a transient browser microphone release abort retries once before re-arm fails', async () => {
    const expectedStream = { id: 'second-acquisition' };
    const calls = [];
    const delays = [];
    const stream = await acquireRealtimeMicrophone(async (constraints) => {
        calls.push(constraints);
        if (calls.length === 1) {
            throw Object.assign(new Error('signal is aborted without reason'), { name: 'AbortError', code: 20 });
        }
        return expectedStream;
    }, { audio: true }, {
        retryDelaysMs: [250, 750, 1500],
        delay: async (milliseconds) => delays.push(milliseconds),
    });

    assert.equal(stream, expectedStream);
    assert.equal(calls.length, 2);
    assert.deepEqual(delays, [250]);

    const permissionFailure = Object.assign(new Error('Permission denied'), { name: 'NotAllowedError' });
    await assert.rejects(
        () => acquireRealtimeMicrophone(async () => { throw permissionFailure; }, { audio: true }, {
            delay: async () => assert.fail('non-transient microphone failures must not retry'),
        }),
        permissionFailure,
    );

    let sustainedAbortCalls = 0;
    const sustainedAbort = Object.assign(new Error('signal is aborted without reason'), { code: 20 });
    const sustainedDelays = [];
    await assert.rejects(
        () => acquireRealtimeMicrophone(async () => {
            sustainedAbortCalls += 1;
            throw sustainedAbort;
        }, { audio: true }, {
            retryDelaysMs: [250, 750, 1500],
            delay: async (milliseconds) => sustainedDelays.push(milliseconds),
        }),
        sustainedAbort,
    );
    assert.equal(sustainedAbortCalls, 4);
    assert.deepEqual(sustainedDelays, [250, 750, 1500]);
});

test('[BEAN-DIAGNOSTIC-01] local wake failures retain only a bounded sanitized cause chain', () => {
    const deepest = Object.assign(new Error('Realtime transcription disconnected.\nBearer secret-must-not-be-copied'), {
        code: 'transport failed!',
    });
    const outer = Object.assign(new Error('The local wake gate could not open safely.'), {
        code: 'gate_open_failed',
        cause: deepest,
    });
    const diagnostic = sanitizedVoiceClientFailure(outer);

    assert.equal(diagnostic.stage, 'local_wake');
    assert.equal(diagnostic.code, 'gate_open_failed');
    assert.equal(diagnostic.cause_chain.length, 2);
    assert.equal(diagnostic.cause_chain[1].code, 'transport_failed_');
    assert.doesNotMatch(JSON.stringify(diagnostic), /secret-must-not-be-copied/);
    assert.match(diagnostic.cause_chain[1].message, /Bearer \[redacted\]/);
});

test('[BEAN-STARTUP-02] startup failures are classified for diagnostics without becoming raw UI copy', () => {
    const diagnostic = sanitizedVoiceClientFailure(
        Object.assign(new Error('signal is aborted without reason'), { code: 'AbortError' }),
        'startup',
    );

    assert.equal(diagnostic.stage, 'startup');
    assert.equal(diagnostic.code, 'AbortError');
    assert.equal(diagnostic.cause_chain.length, 1);
});

test('[BEAN-DIAGNOSTIC-03] connection diagnostics stay content-neutral', () => {
    const hostileMessage = 'api_key=provider-secret transcript=Can_you_hear_me pcm=AAAA';
    const cause = Object.assign(new Error('nested ' + hostileMessage), { code: 'nested provider failure!' });
    const diagnostic = sanitizedVoiceClientFailure(
        Object.assign(new Error('outer ' + hostileMessage), {
            code: 'connection failed!',
            cause,
        }),
        'connection',
    );
    assert.equal(diagnostic.stage, 'connection');
    assert.equal(diagnostic.code, 'voice_connection_failure');
    assert.equal(diagnostic.message, 'Browser voice connection failed.');
    assert.deepEqual(
        diagnostic.cause_chain.map(({ message }) => message),
        ['Browser voice connection failed.', 'Browser voice connection failed.'],
    );
    assert.doesNotMatch(JSON.stringify(diagnostic), /provider-secret|Can_you_hear_me|pcm=|AAAA/);
});

test('[BEAN-DIAGNOSTIC-03] playback authorization failures retain the server-supported playback stage', () => {
    const hostileMessage = 'transcript=private pcm=AAAA provider_secret=secret';
    const diagnostic = sanitizedVoiceClientFailure(
        Object.assign(new Error(hostileMessage), { code: 'approved_text_hash_mismatch' }),
        'playback',
    );
    assert.deepEqual(diagnostic, {
        stage: 'playback',
        code: 'voice_playback_failure',
        message: 'Browser voice playback failed.',
        cause_chain: [{
            code: 'voice_playback_failure',
            message: 'Browser voice playback failed.',
        }],
    });
    assert.doesNotMatch(JSON.stringify(diagnostic), /private|AAAA|secret/);
});

test('[BEAN-DIAGNOSTIC-03] reload-scoped failures identify distinct incidents while one incident retry remains idempotent', async () => {
    const firstPage = createVoiceClientFailureNonce({ randomUUID: () => 'page-attempt-one' });
    const secondPage = createVoiceClientFailureNonce({ randomUUID: () => 'page-attempt-two' });
    const failureId = (stage, pageNonce) => voiceClientFailureId(
        stage,
        voiceClientFailureIdentityParts(stage, [1], pageNonce),
    );

    assert.equal(failureId('startup', firstPage), failureId('startup', firstPage));
    assert.notEqual(failureId('startup', firstPage), failureId('startup', secondPage));
    assert.notEqual(failureId('connection', firstPage), failureId('connection', secondPage));
    assert.notEqual(failureId('local_wake', firstPage), failureId('local_wake', secondPage));
    assert.deepEqual(
        voiceClientFailureIdentityParts('admission', ['durable-turn-1'], firstPage),
        ['durable-turn-1'],
        'durable incident identities must not change merely because the page reloads',
    );

    const sent = [];
    for (const pageNonce of [firstPage, secondPage]) {
        const reporter = new VoiceClientFailureReporter({
            send: async (payload) => sent.push(payload.failure_id),
            eventTarget: null,
            scopeId: 'same-user-across-reload',
        });
        const payload = {
            failure_id: failureId('startup', pageNonce),
            stage: 'startup',
            code: 'AbortError',
            message: 'signal is aborted without reason',
            cause_chain: [],
        };
        assert.equal(reporter.enqueue(payload), true);
        assert.equal(reporter.enqueue(payload), false, 'one incident retry keeps one client event identity');
        assert.equal(await reporter.flush(), true);
        reporter.dispose();
    }
    assert.deepEqual(sent, [failureId('startup', firstPage), failureId('startup', secondPage)]);
});

test('[BEAN-DIAGNOSTIC-03] controlled local worker codes survive content-neutral persistence and hostile codes do not', () => {
    const storage = new MemoryStorage();
    const reporter = new VoiceClientFailureReporter({
        send: async () => {},
        isOnline: () => false,
        eventTarget: null,
        storage,
        scopeId: 'user-worker-code',
    });

    reporter.enqueue({
        failure_id: voiceClientFailureId('local_wake', 'pcm-ack-timeout'),
        stage: 'local_wake',
        code: 'pcm_ack_timeout',
        message: 'transcript=private pcm=AAAA provider_body=secret',
        cause_chain: [{ code: 'initialization_failed', message: 'provider raw content' }],
    });
    reporter.enqueue({
        failure_id: voiceClientFailureId('local_wake', 'hostile-code'),
        stage: 'local_wake',
        code: 'provider_secret_transcript_code',
        message: 'Hey Bean, private transcript',
        cause_chain: [{ code: 'provider_secret_nested', message: 'pcm=BBBB' }],
    });

    const persisted = JSON.parse([...storage.values.values()][0]);
    assert.equal(persisted.pending[0].code, 'pcm_ack_timeout');
    assert.equal(persisted.pending[0].cause_chain[0].code, 'initialization_failed');
    assert.equal(persisted.pending[1].code, 'local_wake_failure');
    assert.equal(persisted.pending[1].cause_chain[0].code, 'local_wake_failure');
    assert.doesNotMatch(
        JSON.stringify(persisted),
        /private transcript|transcript=private|AAAA|BBBB|provider_body|provider raw|secret_transcript/i,
    );
    reporter.dispose();
});

test('[BEAN-DIAGNOSTIC-03] client failure delivery stays pending until success and flushes online or at the next attempt', async () => {
    const listeners = new Map();
    const timers = new Map();
    let nextTimerId = 1;
    let online = true;
    let attempts = 0;
    const sent = [];
    const reporter = new VoiceClientFailureReporter({
        send: async (payload) => {
            attempts += 1;
            sent.push(payload);
            if (attempts === 1) throw Object.assign(new Error('offline'), { status: 503 });
            return { recorded: true };
        },
        isOnline: () => online,
        eventTarget: {
            addEventListener: (type, listener) => listeners.set(type, listener),
            removeEventListener: (type) => listeners.delete(type),
        },
        setTimeout: (callback, milliseconds) => {
            const id = nextTimerId;
            nextTimerId += 1;
            timers.set(id, { callback, milliseconds });
            return id;
        },
        clearTimeout: (id) => timers.delete(id),
        retryDelaysMs: [],
        nextAttemptMs: 250,
        maxPending: 2,
        scopeId: 'user-42',
    });
    const failureId = voiceClientFailureId('connection', 'item-1');
    const payload = {
        failure_id: failureId,
        stage: 'connection',
        code: 'provider failure!',
        message: 'transcript=Can_you_hear_me api_key=provider-secret pcm=AAAA',
        cause_chain: [{
            code: 'provider cause!',
            message: 'Bearer should-not-survive',
        }],
        session_id: 42,
        turn_id: 'voice-turn-1',
        pcm: 'raw-audio-must-not-be-stored',
    };

    assert.equal(reporter.enqueue(payload), true);
    assert.equal(reporter.enqueue(payload), false, 'one stable failure id may have only one pending delivery');
    assert.equal(await reporter.flush(), false);
    assert.equal(reporter.pendingCount(), 1, 'failed delivery remains pending');
    assert.equal(timers.size, 1, 'an exhausted attempt schedules a bounded later flush');
    assert.equal(sent[0].code, 'voice_connection_failure');
    assert.equal(sent[0].message, 'Browser voice connection failed.');
    assert.equal(sent[0].cause_chain[0].code, 'voice_connection_failure');
    assert.equal(sent[0].cause_chain[0].message, 'Browser voice connection failed.');
    assert.equal(Object.hasOwn(sent[0], 'pcm'), false);
    assert.doesNotMatch(JSON.stringify(sent[0]), /provider-secret|Can_you_hear_me|raw-audio|should-not-survive/);

    online = false;
    const [{ callback, milliseconds }] = timers.values();
    assert.equal(milliseconds, 250);
    callback();
    await Promise.resolve();
    assert.equal(attempts, 1, 'the next-attempt flush waits while offline');

    online = true;
    listeners.get('online')();
    assert.equal(await reporter.flush(), true);
    assert.equal(attempts, 2);
    assert.equal(reporter.pendingCount(), 0);
    assert.equal(reporter.enqueue(payload), false, 'delivery is marked only after server success');

    online = false;
    reporter.enqueue({ ...payload, failure_id: voiceClientFailureId('transcription', 'item-2') });
    reporter.enqueue({ ...payload, failure_id: voiceClientFailureId('transcription', 'item-3') });
    reporter.enqueue({ ...payload, failure_id: voiceClientFailureId('transcription', 'item-4') });
    assert.equal(reporter.pendingCount(), 2, 'pending storage stays bounded');
    assert.equal(reporter.overflowCount(), 1, 'capacity loss is retained as an explicit bounded summary');
    reporter.dispose();
    assert.equal(listeners.has('online'), false);
});

test('[BEAN-DIAGNOSTIC-03] offline client failures survive reload and flush once after connectivity returns', async () => {
    const storage = new MemoryStorage();
    let online = false;
    const failureId = voiceClientFailureId('connection', 'reload-turn-1');
    const payload = {
        failure_id: failureId,
        stage: 'connection',
        code: 'raw_provider_code',
        message: 'transcript=Hey_Bean_can_you_hear_me Bearer secret-auth-token pcm=AAAA',
        cause_chain: [{
            code: 'provider_secret_code',
            message: 'Raw provider response must not survive reload.',
        }],
        turn_id: 'voice-turn-reload-1',
        transcript: 'Hey Bean, can you hear me?',
        pcm: 'raw-audio-bytes',
        provider_response: { secret: 'provider-raw-content' },
    };
    const first = new VoiceClientFailureReporter({
        send: async () => assert.fail('offline diagnostics must remain queued'),
        isOnline: () => online,
        eventTarget: null,
        storage,
        scopeId: 'user-17',
    });

    assert.equal(first.enqueue(payload), true);
    assert.equal(first.pendingCount(), 1);
    assert.equal(storage.values.size, 1);
    const [[storageKey, storedValue]] = storage.values.entries();
    assert.doesNotMatch(storageKey, /Bearer|secret-auth-token/);
    assert.doesNotMatch(
        storedValue,
        /Hey_Bean|can_you_hear_me|secret-auth-token|provider_secret|Raw provider|raw-audio|provider-raw-content|pcm/i,
    );
    assert.match(storedValue, /voice_connection_failure/);
    first.dispose();

    const sent = [];
    online = true;
    const second = new VoiceClientFailureReporter({
        send: async (report) => {
            sent.push(report);
            return { recorded: true, duplicate: false };
        },
        isOnline: () => online,
        eventTarget: null,
        storage,
        scopeId: 'user-17',
    });

    assert.equal(await second.flush(), true);
    assert.equal(second.pendingCount(), 0);
    assert.equal(sent.length, 1);
    assert.equal(sent[0].failure_id, failureId);
    assert.equal(sent[0].code, 'voice_connection_failure');
    assert.doesNotMatch(JSON.stringify(sent[0]), /Hey Bean|secret|provider|raw-audio|pcm/i);
    second.dispose();
});

test('[BEAN-DIAGNOSTIC-03] storage quota failure is observable and enqueue never claims durable safety', () => {
    const notifications = [];
    const reporter = new VoiceClientFailureReporter({
        send: async () => assert.fail('the test remains offline'),
        isOnline: () => false,
        eventTarget: null,
        storage: new ThrowingStorage(),
        scopeId: 'user-storage-failure',
        onPersistenceFailure: (diagnostic) => notifications.push(diagnostic),
    });
    const makeFailure = (identity) => ({
        failure_id: voiceClientFailureId('startup', identity),
        stage: 'startup',
        code: 'AbortError',
        message: 'signal is aborted without reason',
        cause_chain: [],
    });

    assert.equal(reporter.enqueue(makeFailure('quota-1')), false);
    assert.equal(reporter.pendingCount(), 1, 'memory may retain the event for an in-page retry');
    assert.equal(reporter.enqueue(makeFailure('quota-2')), false);
    assert.deepEqual(notifications, [{
        code: 'voice_diagnostic_outbox_persist_failed',
        message: 'Browser voice diagnostics could not be saved for reload recovery.',
    }]);
    reporter.dispose();
});

test('[BEAN-DIAGNOSTIC-03] capacity keeps oldest failures and emits one explicit overflow diagnostic', async () => {
    const storage = new MemoryStorage();
    let online = false;
    const sent = [];
    const reporter = new VoiceClientFailureReporter({
        send: async (report) => {
            sent.push(report);
            return { recorded: true };
        },
        isOnline: () => online,
        eventTarget: null,
        storage,
        scopeId: 'user-capacity',
        maxPending: 2,
    });
    const makeFailure = (identity) => ({
        failure_id: voiceClientFailureId('startup', identity),
        stage: 'startup',
        code: 'provider_startup_failure',
        message: `private transcript ${identity}`,
        cause_chain: [],
    });
    const first = makeFailure('first');
    const second = makeFailure('second');
    const third = makeFailure('third');

    assert.equal(reporter.enqueue(first), true);
    assert.equal(reporter.enqueue(second), true);
    assert.equal(reporter.enqueue(third), true, 'the omitted event is accepted into the overflow summary');
    assert.equal(reporter.enqueue(third), false, 'the same omitted failure id is counted only once per overflow episode');
    assert.equal(reporter.pendingCount(), 2);
    assert.equal(reporter.overflowCount(), 1);

    const persisted = JSON.parse([...storage.values.values()][0]);
    assert.deepEqual(persisted.pending.map(({ failure_id }) => failure_id), [first.failure_id, second.failure_id]);
    assert.equal(persisted.overflow.count, 1);

    online = true;
    while (reporter.pendingCount() > 0) {
        await reporter.flush();
        await Promise.resolve();
    }
    assert.deepEqual(sent.slice(0, 2).map(({ failure_id }) => failure_id), [first.failure_id, second.failure_id]);
    const overflow = sent.find(({ code }) => code === 'voice_diagnostic_outbox_overflow');
    assert.ok(overflow, 'capacity loss reaches the existing diagnostic endpoint explicitly');
    assert.equal(overflow.cause_chain[0].code, 'overflow_count_1');
    assert.equal(sent.some(({ failure_id }) => failure_id === third.failure_id), false);
    reporter.dispose();
});

test('[BEAN-DIAGNOSTIC-03] recreated overflow state cannot collide with a prior durable overflow identity', async () => {
    const storage = new MemoryStorage();
    const overflowIds = [];
    const scopeNonces = ['scope-attempt-one', 'scope-attempt-two'];
    let online = false;
    const reporter = new VoiceClientFailureReporter({
        send: async (report) => {
            if (report.code === 'voice_diagnostic_outbox_overflow') overflowIds.push(report.failure_id);
        },
        isOnline: () => online,
        eventTarget: null,
        storage,
        scopeId: 'user-overflow-recreate',
        nonceFactory: () => scopeNonces.shift(),
        maxPending: 1,
    });
    const runEpisode = async (identity) => {
        reporter.enqueue({
            failure_id: voiceClientFailureId('startup', `${identity}-first`),
            stage: 'startup',
            code: 'AbortError',
            message: 'startup failed',
            cause_chain: [],
        });
        reporter.enqueue({
            failure_id: voiceClientFailureId('startup', `${identity}-overflow`),
            stage: 'startup',
            code: 'AbortError',
            message: 'startup failed again',
            cause_chain: [],
        });
        online = true;
        while (reporter.pendingCount() > 0) {
            await reporter.flush();
            await Promise.resolve();
        }
        online = false;
    };

    await runEpisode('first-episode');
    const [storageKey] = storage.values.keys();
    storage.removeItem(storageKey);
    reporter.reset();
    await runEpisode('second-episode');
    assert.equal(overflowIds.length, 2);
    assert.notEqual(overflowIds[0], overflowIds[1]);
    reporter.dispose();
});

test('[BEAN-DIAGNOSTIC-03] corrupted persisted diagnostics are discarded safely and reported without their content', async () => {
    const storage = new MemoryStorage();
    const seed = new VoiceClientFailureReporter({
        send: async () => {},
        isOnline: () => false,
        eventTarget: null,
        storage,
        scopeId: 'user-corrupt',
    });
    const [storageKey] = storage.values.keys();
    seed.dispose();
    storage.setItem(storageKey, '{"transcript":"Hey Bean secret transcript","pcm":"AAAA"');

    const sent = [];
    const reporter = new VoiceClientFailureReporter({
        send: async (report) => sent.push(report),
        isOnline: () => true,
        eventTarget: null,
        storage,
        scopeId: 'user-corrupt',
    });
    assert.equal(await reporter.flush(), true);
    assert.equal(sent.length, 1);
    assert.equal(sent[0].code, 'voice_diagnostic_outbox_corrupt');
    assert.equal(sent[0].message, 'Browser voice diagnostic outbox discarded corrupted local state.');
    assert.doesNotMatch(JSON.stringify(sent[0]), /Hey Bean|secret transcript|AAAA|pcm/i);
    assert.doesNotMatch(storage.getItem(storageKey), /Hey Bean|secret transcript|AAAA|pcm/i);
    reporter.dispose();
});

test('[BEAN-DIAGNOSTIC-03] login scope switching isolates users and explicit deletion clears only the selected scope', () => {
    const storage = new MemoryStorage();
    const reporter = new VoiceClientFailureReporter({
        send: async () => {},
        isOnline: () => false,
        eventTarget: null,
        storage,
        scopeId: 'user-a',
    });
    const userAFailure = {
        failure_id: voiceClientFailureId('local_wake', 'user-a-failure'),
        stage: 'local_wake',
        code: 'private_code_a',
        message: 'private message a',
        cause_chain: [],
    };
    const userBFailure = {
        failure_id: voiceClientFailureId('local_wake', 'user-b-failure'),
        stage: 'local_wake',
        code: 'private_code_b',
        message: 'private message b',
        cause_chain: [],
    };

    reporter.enqueue(userAFailure);
    reporter.setScope('user-b');
    reporter.enqueue(userBFailure);
    assert.equal(storage.values.size, 2);
    reporter.deactivateCurrentScope();
    assert.equal(storage.values.size, 2, 'normal logout preserves both users’ unsent diagnostics');
    reporter.setScope('user-b');
    reporter.clearCurrentScope();
    assert.equal(storage.values.size, 1, 'explicit account deletion removes only user B diagnostic state');

    reporter.setScope('user-a');
    assert.equal(reporter.pendingCount(), 1);
    assert.match(JSON.stringify([...storage.values.values()]), /user-a-failure/);
    assert.doesNotMatch(JSON.stringify([...storage.values.values()]), /user-b-failure/);
    reporter.dispose();
});

test('[BEAN-DIAGNOSTIC-03] normal logout preserves unsent diagnostics and same-user login flushes them once', async () => {
    const storage = new MemoryStorage();
    let online = false;
    const sent = [];
    const reporter = new VoiceClientFailureReporter({
        send: async (report) => sent.push(report),
        isOnline: () => online,
        eventTarget: null,
        storage,
        scopeId: 'user-logout-recovery',
    });
    const failure = {
        failure_id: voiceClientFailureId('local_wake', 'logout-recovery-incident'),
        stage: 'local_wake',
        code: 'pcm_ack_timeout',
        message: 'The worker stopped acknowledging PCM.',
        cause_chain: [],
    };

    assert.equal(reporter.enqueue(failure), true);
    reporter.deactivateCurrentScope();
    assert.equal(reporter.pendingCount(), 0);
    assert.equal(storage.values.size, 1);

    online = true;
    reporter.setScope('user-logout-recovery');
    assert.equal(await reporter.flush(), true);
    assert.equal(sent.length, 1);
    assert.equal(sent[0].failure_id, failure.failure_id);

    reporter.deactivateCurrentScope();
    reporter.setScope('user-logout-recovery');
    assert.equal(await reporter.flush(), true);
    assert.equal(sent.length, 1, 'the durable server event is not resubmitted after local success');
    reporter.dispose();
});

test('microphone capture requests browser echo and noise controls', () => {
    assert.deepEqual(realtimeMicrophoneConstraints(), {
        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
    });
});

test('chat rerenders restore textarea focus, selection, and scroll without moving the page', () => {
    const form = { dataset: { chatInstance: 'primary-chat' } };
    const original = {
        dataset: { chatFocusControl: 'message' },
        selectionStart: 2,
        selectionEnd: 6,
        selectionDirection: 'forward',
        scrollTop: 11,
        closest(selector) { return selector === '[data-chat-focus-control]' ? this : form; },
    };
    const replacement = {
        disabled: false,
        value: 'new',
        focusOptions: null,
        selection: null,
        scrollTop: 0,
        focus(options) { this.focusOptions = options; },
        setSelectionRange(...selection) { this.selection = selection; },
    };
    form.querySelector = (selector) => selector.includes('message') ? replacement : null;
    const mount = {
        ownerDocument: { activeElement: original },
        contains: (element) => element === original,
        querySelectorAll: () => [form],
    };

    const snapshot = captureHeyBeanChatControlFocus(mount);
    assert.equal(restoreHeyBeanChatControlFocus(mount, snapshot), true);
    assert.deepEqual(replacement.focusOptions, { preventScroll: true });
    assert.deepEqual(replacement.selection, [2, 3, 'forward']);
    assert.equal(replacement.scrollTop, 11);
});

test('a removed stop control hands keyboard focus to the stable send control', () => {
    const send = { disabled: false, focused: false, focus() { this.focused = true; } };
    const form = {
        dataset: { chatInstance: 'primary-chat' },
        querySelector: (selector) => selector.includes('send') ? send : null,
    };
    const mount = { querySelectorAll: () => [form] };
    assert.equal(restoreHeyBeanChatControlFocus(mount, {
        instance: 'primary-chat',
        control: 'stop',
        selectionStart: null,
    }), true);
    assert.equal(send.focused, true);
});
