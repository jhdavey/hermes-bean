import { BROWSER_VOICE_CONVERSATION_STATES } from './browserVoiceControllerV2.js';

export const BROWSER_VOICE_REALTIME_INGRESS_RESULTS = Object.freeze({
    ROUTED: 'routed',
    POTENTIAL_BARGE_IN: 'potential_barge_in',
    CAPACITY_EXHAUSTED: 'capacity_exhausted',
    IGNORED: 'ignored',
});

export const BROWSER_VOICE_PROVIDER_ITEM_BINDING_MODES = Object.freeze({
    TURN: 'turn',
    BARGE: 'barge',
});

export const BROWSER_VOICE_PROVIDER_ITEM_CAPACITY_EXHAUSTED = Object.freeze({
    status: BROWSER_VOICE_REALTIME_INGRESS_RESULTS.CAPACITY_EXHAUSTED,
});

const DEFAULT_PROVIDER_ITEM_LIMIT = 256;

function cleanIdentity(value) {
    return String(value || '').trim();
}

function providerItemKey(providerItemId, connectionGeneration) {
    const itemId = cleanIdentity(providerItemId);
    const generation = Number(connectionGeneration);
    if (!itemId || !Number.isSafeInteger(generation) || generation < 0) return '';
    return JSON.stringify([generation, itemId]);
}

function sameIdentityList(left, right) {
    return Array.isArray(left)
        && Array.isArray(right)
        && left.length === right.length
        && left.every((value, index) => cleanIdentity(value) === cleanIdentity(right[index]));
}

function appendClosedIdentity(closedTurnIds, turnId, limit) {
    const identities = Array.isArray(closedTurnIds) ? closedTurnIds.map(cleanIdentity).filter(Boolean) : [];
    const identity = cleanIdentity(turnId);
    if (!identity || identities.includes(identity)) return identities;
    return [...identities, identity].slice(-Math.max(1, Number(limit) || 1));
}

export function browserVoiceProviderItemCapacityExhaustedV2(value) {
    return value === BROWSER_VOICE_PROVIDER_ITEM_CAPACITY_EXHAUSTED;
}

export function createBrowserVoiceProviderTurnIdentityV2(controller, {
    turnId = '',
    inputGeneration = null,
    throughSourceSequence = null,
    providerConnectionGeneration = null,
} = {}) {
    if (!controller || typeof controller.snapshot !== 'function') return null;
    const snapshot = controller.snapshot();
    const selectedTurnId = cleanIdentity(turnId)
        || cleanIdentity(snapshot.followUpCandidate?.id)
        || cleanIdentity(snapshot.activeTurn?.id);
    const normalizedInputGeneration = Number(inputGeneration);
    const normalizedSourceSequence = Number(throughSourceSequence);
    const normalizedProviderConnection = Number(
        providerConnectionGeneration ?? snapshot.connectionGeneration,
    );
    if (!selectedTurnId
        || !Number.isSafeInteger(normalizedInputGeneration) || normalizedInputGeneration < 0
        || !Number.isSafeInteger(normalizedSourceSequence) || normalizedSourceSequence < 0) return null;
    if (!Number.isSafeInteger(normalizedProviderConnection) || normalizedProviderConnection < 0) return null;

    return Object.freeze({
        turnId: selectedTurnId,
        controllerGeneration: Number(snapshot.generation),
        controllerConnectionGeneration: Number(snapshot.connectionGeneration),
        conversationEpoch: Number(snapshot.conversationEpoch),
        providerConnectionGeneration: normalizedProviderConnection,
        inputGeneration: normalizedInputGeneration,
        throughSourceSequence: normalizedSourceSequence,
    });
}

export function browserVoiceProviderTurnIdentityIsCurrentV2(controller, identity, {
    inputGeneration = null,
    providerConnectionGeneration = null,
    throughSourceSequence = null,
} = {}) {
    if (!controller || typeof controller.snapshot !== 'function' || !identity) return false;
    const snapshot = controller.snapshot();
    const currentInputGeneration = Number(inputGeneration);
    const currentProviderConnection = Number(providerConnectionGeneration);
    const currentSourceSequence = Number(throughSourceSequence);
    if (inputGeneration === null || inputGeneration === ''
        || providerConnectionGeneration === null || providerConnectionGeneration === ''
        || throughSourceSequence === null || throughSourceSequence === ''
        || !Number.isSafeInteger(currentInputGeneration) || currentInputGeneration < 0
        || !Number.isSafeInteger(currentProviderConnection) || currentProviderConnection < 0) return false;
    if (!Number.isSafeInteger(currentSourceSequence) || currentSourceSequence < 0) return false;
    const currentTurnId = cleanIdentity(snapshot.followUpCandidate?.id)
        || cleanIdentity(snapshot.activeTurn?.id);
    return Boolean(currentTurnId)
        && currentTurnId === cleanIdentity(identity.turnId)
        && Number(snapshot.generation) === Number(identity.controllerGeneration)
        && Number(snapshot.connectionGeneration) === Number(identity.controllerConnectionGeneration)
        && Number(snapshot.conversationEpoch) === Number(identity.conversationEpoch)
        && Number.isSafeInteger(Number(identity.inputGeneration))
        && Number(identity.inputGeneration) >= 0
        && Number(identity.inputGeneration) === currentInputGeneration
        && Number(identity.providerConnectionGeneration) === currentProviderConnection
        && Number.isSafeInteger(Number(identity.throughSourceSequence))
        && Number(identity.throughSourceSequence) >= 0
        && currentSourceSequence >= Number(identity.throughSourceSequence);
}

/**
 * Bounded identity registry for provider transcription items. It owns no
 * conversation lifecycle: it only seals the exact provider-item/turn
 * association established when provider VAD starts an item. Consumed and
 * unowned identities remain as scoped tombstones so late/duplicate events
 * cannot attach themselves to a newer turn.
 */
export class BrowserVoiceProviderItemRegistryV2 {
    constructor({ limit = DEFAULT_PROVIDER_ITEM_LIMIT } = {}) {
        const normalizedLimit = Number(limit);
        if (!Number.isSafeInteger(normalizedLimit) || normalizedLimit < 1) {
            throw new TypeError('Browser voice provider item limit must be a positive integer.');
        }
        this.limit = normalizedLimit;
        this.records = new Map();
    }

    bind({
        providerItemId,
        turnId,
        connectionGeneration,
        controllerGeneration,
        controllerConnectionGeneration,
        conversationEpoch,
        mode = BROWSER_VOICE_PROVIDER_ITEM_BINDING_MODES.TURN,
        closedTurnIds = [],
        postPlaybackClosedTurnIds = [],
    } = {}) {
        const key = providerItemKey(providerItemId, connectionGeneration);
        const normalizedTurnId = cleanIdentity(turnId);
        const generation = Number(controllerGeneration);
        const controllerConnection = Number(controllerConnectionGeneration);
        const epoch = Number(conversationEpoch);
        const normalizedMode = cleanIdentity(mode);
        if (!key || !normalizedTurnId
            || !Number.isSafeInteger(generation) || generation < 0
            || !Number.isSafeInteger(controllerConnection) || controllerConnection < 0
            || !Number.isSafeInteger(epoch) || epoch < 0
            || !Object.values(BROWSER_VOICE_PROVIDER_ITEM_BINDING_MODES).includes(normalizedMode)) return null;

        const existing = this.records.get(key);
        if (existing?.sealed) return null;
        if (existing?.binding) {
            const binding = existing.binding;
            return binding.turnId === normalizedTurnId
                && binding.controllerGeneration === generation
                && binding.controllerConnectionGeneration === controllerConnection
                && binding.conversationEpoch === epoch
                && binding.mode === normalizedMode
                ? binding
                : null;
        }
        if (this.records.size >= this.limit) return BROWSER_VOICE_PROVIDER_ITEM_CAPACITY_EXHAUSTED;

        const binding = Object.freeze({
            providerItemId: cleanIdentity(providerItemId),
            turnId: normalizedTurnId,
            connectionGeneration: Number(connectionGeneration),
            controllerGeneration: generation,
            controllerConnectionGeneration: controllerConnection,
            conversationEpoch: epoch,
            mode: normalizedMode,
            closedTurnIds: Object.freeze([...closedTurnIds].map(cleanIdentity).filter(Boolean)),
            postPlaybackClosedTurnIds: Object.freeze(
                [...postPlaybackClosedTurnIds].map(cleanIdentity).filter(Boolean),
            ),
        });
        this.#remember(key, { binding, sealed: false });
        return binding;
    }

    has({ providerItemId, connectionGeneration } = {}) {
        const key = providerItemKey(providerItemId, connectionGeneration);
        return Boolean(key && this.records.has(key));
    }

    lookup({ providerItemId, connectionGeneration } = {}) {
        const key = providerItemKey(providerItemId, connectionGeneration);
        const record = key ? this.records.get(key) : null;
        if (key && !record && this.records.size >= this.limit) {
            return BROWSER_VOICE_PROVIDER_ITEM_CAPACITY_EXHAUSTED;
        }
        return record && !record.sealed ? record.binding : null;
    }

    consume({ providerItemId, connectionGeneration } = {}) {
        const key = providerItemKey(providerItemId, connectionGeneration);
        if (!key) return null;
        const record = this.records.get(key);
        if (record?.sealed) return null;
        if (!record && this.records.size >= this.limit) {
            return BROWSER_VOICE_PROVIDER_ITEM_CAPACITY_EXHAUSTED;
        }
        const binding = record?.binding || null;
        this.#remember(key, { binding: null, sealed: true });
        return binding;
    }

    clear() {
        this.records.clear();
    }

    get size() {
        return this.records.size;
    }

    get capacityExhausted() {
        return this.records.size >= this.limit;
    }

    #remember(key, record) {
        this.records.delete(key);
        this.records.set(key, record);
    }
}

export function browserVoiceProviderItemBindingIsCurrentV2(controller, binding) {
    if (!controller || typeof controller.snapshot !== 'function' || !binding
        || browserVoiceProviderItemCapacityExhaustedV2(binding)) return false;
    const snapshot = controller.snapshot();
    if (Number(snapshot.generation) !== Number(binding.controllerGeneration)
        || Number(snapshot.connectionGeneration) !== Number(binding.controllerConnectionGeneration)
        || Number(snapshot.conversationEpoch) !== Number(binding.conversationEpoch)) return false;
    const currentTurnId = cleanIdentity(snapshot.followUpCandidate?.id)
        || cleanIdentity(snapshot.activeTurn?.id);
    if (binding.mode === BROWSER_VOICE_PROVIDER_ITEM_BINDING_MODES.TURN) {
        return Boolean(currentTurnId && currentTurnId === cleanIdentity(binding.turnId));
    }
    if (binding.mode !== BROWSER_VOICE_PROVIDER_ITEM_BINDING_MODES.BARGE) return false;
    const pendingBarge = snapshot.potentialBargeIn;
    const pendingBargeMatches = cleanIdentity(pendingBarge?.ownerTurnId) === cleanIdentity(binding.turnId)
        && cleanIdentity(pendingBarge?.providerItemId) === cleanIdentity(binding.providerItemId);
    if (currentTurnId) {
        return currentTurnId === cleanIdentity(binding.turnId)
            && sameIdentityList(snapshot.closedTurnIds, binding.closedTurnIds)
            && (!pendingBarge || pendingBargeMatches);
    }
    return pendingBargeMatches
        && snapshot.conversationState === BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP
        && sameIdentityList(snapshot.closedTurnIds, binding.postPlaybackClosedTurnIds);
}

export function currentBrowserVoiceProviderItemBindingV2(registry, controller, {
    providerItemId,
    connectionGeneration,
    consume = false,
} = {}) {
    if (!(registry instanceof BrowserVoiceProviderItemRegistryV2)) {
        throw new TypeError('Browser voice provider ingress requires its item registry.');
    }
    const binding = consume
        ? registry.consume({ providerItemId, connectionGeneration })
        : registry.lookup({ providerItemId, connectionGeneration });
    if (browserVoiceProviderItemCapacityExhaustedV2(binding)) return binding;
    if (browserVoiceProviderItemBindingIsCurrentV2(controller, binding)) return binding;
    if (binding && !consume) registry.consume({ providerItemId, connectionGeneration });
    return null;
}

/**
 * Translate provider input events into the existing conversation controller.
 * This adapter owns no lifecycle state; the controller remains the sole owner
 * of follow-up admission, endpoint timing, and exactly-once turn delivery.
 */
export function routeBrowserVoiceRealtimeIngressV2(controller, {
    type,
    text = '',
    providerItemId = null,
} = {}) {
    if (!controller || typeof controller.snapshot !== 'function') {
        throw new TypeError('Browser voice realtime ingress requires the conversation controller.');
    }
    const metadata = {
        source: type === 'speech_started' ? 'provider_vad' : 'provider_transcript',
        providerItemId: providerItemId || null,
    };

    if (type === 'speech_started') {
        if (controller.snapshot().speechActive) {
            return BROWSER_VOICE_REALTIME_INGRESS_RESULTS.POTENTIAL_BARGE_IN;
        }
        controller.speechStarted(metadata);
        return BROWSER_VOICE_REALTIME_INGRESS_RESULTS.ROUTED;
    }
    if (type === 'transcript_partial') {
        controller.transcriptPartial(text, metadata);
        return BROWSER_VOICE_REALTIME_INGRESS_RESULTS.ROUTED;
    }
    if (type === 'transcript_final') {
        controller.transcriptFinal(text, metadata);
        const snapshot = controller.snapshot();
        if (snapshot.conversationState === BROWSER_VOICE_CONVERSATION_STATES.CAPTURING) {
            controller.speechEnded({
                source: 'provider_vad',
                observedSilenceMs: snapshot.config.endpointMs,
            });
        }
        return BROWSER_VOICE_REALTIME_INGRESS_RESULTS.ROUTED;
    }

    return BROWSER_VOICE_REALTIME_INGRESS_RESULTS.IGNORED;
}

/**
 * Route provider VAD first, then bind its item to the exact turn selected by
 * the conversation controller. Duplicate/tombstoned item IDs are ignored
 * before they can mutate controller state.
 */
export function bindBrowserVoiceProviderSpeechStartedV2(controller, registry, {
    providerItemId,
    connectionGeneration,
    turnId = '',
    turnIdentity = null,
    inputGeneration = null,
    throughSourceSequence = null,
} = {}) {
    if (!(registry instanceof BrowserVoiceProviderItemRegistryV2)) {
        throw new TypeError('Browser voice provider ingress requires its item registry.');
    }
    const itemId = cleanIdentity(providerItemId);
    if (!itemId || registry.has({ providerItemId: itemId, connectionGeneration })) {
        return { result: BROWSER_VOICE_REALTIME_INGRESS_RESULTS.IGNORED, binding: null };
    }
    if (registry.capacityExhausted) {
        return {
            result: BROWSER_VOICE_REALTIME_INGRESS_RESULTS.CAPACITY_EXHAUSTED,
            binding: null,
            capacityExhausted: true,
        };
    }

    if (turnIdentity && !browserVoiceProviderTurnIdentityIsCurrentV2(controller, turnIdentity, {
        inputGeneration,
        providerConnectionGeneration: connectionGeneration,
        throughSourceSequence,
    })) {
        registry.consume({ providerItemId: itemId, connectionGeneration });
        return {
            result: BROWSER_VOICE_REALTIME_INGRESS_RESULTS.IGNORED,
            binding: null,
            staleTurnIdentity: true,
        };
    }

    const result = routeBrowserVoiceRealtimeIngressV2(controller, {
        type: 'speech_started',
        providerItemId: itemId,
    });
    const snapshot = controller.snapshot();
    const selectedTurnId = cleanIdentity(turnIdentity?.turnId)
        || cleanIdentity(turnId)
        || cleanIdentity(snapshot.followUpCandidate?.id)
        || cleanIdentity(snapshot.activeTurn?.id);
    const mode = result === BROWSER_VOICE_REALTIME_INGRESS_RESULTS.POTENTIAL_BARGE_IN
        ? BROWSER_VOICE_PROVIDER_ITEM_BINDING_MODES.BARGE
        : BROWSER_VOICE_PROVIDER_ITEM_BINDING_MODES.TURN;
    const closedTurnIds = [...snapshot.closedTurnIds];
    const binding = registry.bind({
        providerItemId: itemId,
        turnId: selectedTurnId,
        connectionGeneration,
        controllerGeneration: turnIdentity?.controllerGeneration ?? snapshot.generation,
        controllerConnectionGeneration: turnIdentity?.controllerConnectionGeneration
            ?? snapshot.connectionGeneration,
        conversationEpoch: turnIdentity?.conversationEpoch ?? snapshot.conversationEpoch,
        mode,
        closedTurnIds,
        postPlaybackClosedTurnIds: mode === BROWSER_VOICE_PROVIDER_ITEM_BINDING_MODES.BARGE
            ? appendClosedIdentity(closedTurnIds, selectedTurnId, snapshot.config.closedTurnLimit)
            : closedTurnIds,
    });
    if (browserVoiceProviderItemCapacityExhaustedV2(binding)) {
        return {
            result: BROWSER_VOICE_REALTIME_INGRESS_RESULTS.CAPACITY_EXHAUSTED,
            binding: null,
            capacityExhausted: true,
        };
    }
    if (!browserVoiceProviderItemBindingIsCurrentV2(controller, binding)) {
        registry.consume({ providerItemId: itemId, connectionGeneration });
        return { result: BROWSER_VOICE_REALTIME_INGRESS_RESULTS.IGNORED, binding: null };
    }
    return { result, binding };
}
