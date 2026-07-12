import {
    BROWSER_VOICE_FOLLOW_UP_RELEVANCE,
    classifyBrowserVoiceFollowUpRelevance,
} from './browserVoiceFollowUpRelevanceV2.js';

export const BROWSER_VOICE_CONVERSATION_STATES = Object.freeze({
    OFF: 'off',
    STARTING: 'starting',
    WAKE_ONLY: 'wake_only',
    ACTIVATING: 'activating',
    CAPTURING: 'capturing',
    AWAITING_CLARIFICATION: 'awaiting_clarification',
    FOLLOW_UP: 'follow_up',
    RECOVERING_CONNECTION: 'recovering_connection',
    FAILED: 'failed',
});

export const BROWSER_VOICE_TIMER_KEYS = Object.freeze({
    ENDPOINT: 'endpoint',
    CLARIFICATION: 'clarification',
    FOLLOW_UP: 'follow_up',
});

export const BROWSER_VOICE_EFFECTS = Object.freeze({
    ACTIVATE_CAPTURE: 'activate_capture',
    ASSESS_COMPLETENESS: 'assess_completeness',
    CANCEL_ALL_TIMERS: 'cancel_all_timers',
    CANCEL_TIMER: 'cancel_timer',
    CLARIFICATION_EXPIRED: 'clarification_expired',
    CONFIRM_INTERRUPTION: 'confirm_interruption',
    DRAFT_CHANGED: 'draft_changed',
    DISCARD_FOLLOW_UP_CANDIDATE: 'discard_follow_up_candidate',
    DUCK_PLAYBACK: 'duck_playback',
    EVENT_REJECTED: 'event_rejected',
    RESTORE_PLAYBACK: 'restore_playback',
    SCHEDULE_TIMER: 'schedule_timer',
    SPEAK_CLARIFICATION: 'speak_clarification',
    STOP_PLAYBACK: 'stop_playback',
    TURN_READY: 'turn_ready',
});

export const BROWSER_VOICE_CONTEXT_MODES = Object.freeze({
    NEW_CONVERSATION: 'new_conversation',
    CONTEXTUAL_FOLLOW_UP: 'contextual_follow_up',
});

const UNSCOPED_EVENT_TYPES = new Set([
    'start',
    'disable',
    'reconnect_started',
    'stop_playback',
]);

const TURN_SCOPED_EVENT_TYPES = new Set([
    'transcript_partial',
    'transcript_final',
    'speech_ended',
    'completeness_decided',
    'capture_failed',
    'follow_up_candidate_rejected',
    'admission_clarification_required',
    'admission_failed',
]);

const DEFAULT_CONFIG = Object.freeze({
    endpointMs: 2_000,
    clarificationMs: 5_000,
    followUpMs: 15_000,
    closedTurnLimit: 128,
});

function cleanText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
}

function joinSegments(segments, draft = '') {
    return [...segments, draft].map(cleanText).filter(Boolean).join(' ');
}

function appendClosedTurn(closedTurnIds, turnId, limit) {
    const id = String(turnId || '').trim();
    if (!id || closedTurnIds.includes(id)) return closedTurnIds;
    return [...closedTurnIds, id].slice(-limit);
}

function effect(type, detail = {}) {
    return Object.freeze({ type, ...detail });
}

function unchanged(state, effects = []) {
    return { state, effects };
}

function rejectEvent(state, event, reason) {
    const rejected = {
        reason,
        type: String(event?.type || ''),
        source: String(event?.source || 'unknown'),
        sequence: Number.isSafeInteger(event?.sequence) ? event.sequence : null,
        turnId: String(event?.turnId || '') || null,
        atMs: Number.isFinite(event?.atMs) ? event.atMs : null,
    };
    return {
        state: {
            ...state,
            rejectedEventCount: state.rejectedEventCount + 1,
            lastRejectedEvent: rejected,
        },
        effects: [effect(BROWSER_VOICE_EFFECTS.EVENT_REJECTED, rejected)],
    };
}

function timerEffectsForState(state, atMs) {
    const timerSpecs = [
        [BROWSER_VOICE_TIMER_KEYS.ENDPOINT, state.deadlines.endpointAt],
        [BROWSER_VOICE_TIMER_KEYS.CLARIFICATION, state.deadlines.clarificationAt],
        [BROWSER_VOICE_TIMER_KEYS.FOLLOW_UP, state.deadlines.followUpAt],
    ];
    return timerSpecs
        .filter(([, deadline]) => Number.isFinite(deadline))
        .map(([timerKey, deadline]) => effect(BROWSER_VOICE_EFFECTS.SCHEDULE_TIMER, {
            timerKey,
            delayMs: Math.max(0, deadline - atMs),
            turnId: timerKey === BROWSER_VOICE_TIMER_KEYS.FOLLOW_UP ? null : state.activeTurn?.id || null,
        }));
}

function beginTurn(state, event, originState) {
    const turnId = cleanText(event.turnId);
    if (!turnId) return rejectEvent(state, event, 'missing_turn_id');

    let closedTurnIds = state.closedTurnIds;
    if (state.activeTurn?.id && state.activeTurn.id !== turnId) {
        closedTurnIds = appendClosedTurn(closedTurnIds, state.activeTurn.id, state.config.closedTurnLimit);
    }
    if (state.followUpCandidate?.id && state.followUpCandidate.id !== turnId) {
        closedTurnIds = appendClosedTurn(closedTurnIds, state.followUpCandidate.id, state.config.closedTurnLimit);
    }

    const wasSpeaking = state.speechActive;
    const providerWakeOwnsCandidate = Boolean(
        state.followUpCandidate?.providerItemId
        && cleanText(event.providerItemId) === state.followUpCandidate.providerItemId,
    );
    const contextualFollowUp = originState === BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP;
    const conversationEpoch = contextualFollowUp
        ? state.conversationEpoch
        : state.conversationEpoch + 1;
    const next = {
        ...state,
        conversationEpoch,
        conversationState: BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING,
        activeTurn: {
            id: turnId,
            originState,
            conversationContext: {
                mode: contextualFollowUp
                    ? BROWSER_VOICE_CONTEXT_MODES.CONTEXTUAL_FOLLOW_UP
                    : BROWSER_VOICE_CONTEXT_MODES.NEW_CONVERSATION,
                epoch: conversationEpoch,
            },
            segments: [],
            draft: '',
            finalizedDraft: '',
            assessmentPending: false,
            submitted: false,
            clarificationQuestion: '',
            clarificationContinuation: false,
        },
        followUpCandidate: null,
        liveDraft: '',
        finalizedTranscript: '',
        closeAfterPlayback: false,
        speechActive: false,
        closedTurnIds,
        deadlines: { endpointAt: null, clarificationAt: null, followUpAt: null },
    };
    return {
        state: next,
        effects: [
            effect(BROWSER_VOICE_EFFECTS.CANCEL_ALL_TIMERS),
            ...(state.followUpCandidate && !providerWakeOwnsCandidate ? [effect(BROWSER_VOICE_EFFECTS.DISCARD_FOLLOW_UP_CANDIDATE, {
                turnId: state.followUpCandidate.id,
                providerItemId: state.followUpCandidate.providerItemId || null,
                reason: 'strict_wake',
            })] : []),
            effect(BROWSER_VOICE_EFFECTS.DRAFT_CHANGED, { text: '', turnId }),
            ...(wasSpeaking || event.playbackOwned
                ? [effect(BROWSER_VOICE_EFFECTS.STOP_PLAYBACK, { reason: event.reason || 'wake' })]
                : []),
            effect(BROWSER_VOICE_EFFECTS.ACTIVATE_CAPTURE, { turnId, reason: event.reason || 'wake' }),
        ],
    };
}

function beginFollowUpCandidate(state, event, atMs) {
    const turnId = cleanText(event.turnId);
    if (!turnId) return rejectEvent(state, event, 'missing_turn_id');
    if (state.followUpCandidate) return rejectEvent(state, event, 'follow_up_candidate_active');

    const remainingMs = Number.isFinite(state.deadlines.followUpAt)
        ? Math.max(0, state.deadlines.followUpAt - atMs)
        : null;
    return {
        state: {
            ...state,
            followUpCandidate: {
                id: turnId,
                draft: '',
                finalizedDraft: '',
                remainingMs,
                providerItemId: cleanText(event.providerItemId) || null,
            },
            deadlines: { ...state.deadlines, followUpAt: null },
        },
        effects: [effect(BROWSER_VOICE_EFFECTS.CANCEL_TIMER, {
            timerKey: BROWSER_VOICE_TIMER_KEYS.FOLLOW_UP,
        })],
    };
}

function rejectFollowUpCandidate(state, event, atMs, reason = 'not_meaningful') {
    const candidate = state.followUpCandidate;
    if (!candidate) return rejectEvent(state, event, 'no_follow_up_candidate');

    const hasRemainingWindow = Number.isFinite(candidate.remainingMs) && candidate.remainingMs > 0;
    const hasUntimedWindow = candidate.remainingMs === null;
    const conversationState = hasRemainingWindow || hasUntimedWindow
        ? BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP
        : BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY;
    const followUpAt = hasRemainingWindow ? atMs + candidate.remainingMs : null;
    return {
        state: {
            ...state,
            conversationState,
            followUpCandidate: null,
            liveDraft: '',
            closedTurnIds: appendClosedTurn(state.closedTurnIds, candidate.id, state.config.closedTurnLimit),
            deadlines: { ...state.deadlines, endpointAt: null, followUpAt },
        },
        effects: [
            effect(BROWSER_VOICE_EFFECTS.DISCARD_FOLLOW_UP_CANDIDATE, {
                turnId: candidate.id,
                providerItemId: candidate.providerItemId || cleanText(event.providerItemId) || null,
                reason,
            }),
            ...(hasRemainingWindow ? [effect(BROWSER_VOICE_EFFECTS.SCHEDULE_TIMER, {
                timerKey: BROWSER_VOICE_TIMER_KEYS.FOLLOW_UP,
                delayMs: candidate.remainingMs,
                turnId: null,
            })] : []),
        ],
    };
}

function promoteFollowUpCandidate(state, text, final) {
    const candidate = state.followUpCandidate;
    let closedTurnIds = state.closedTurnIds;
    if (state.activeTurn?.id && state.activeTurn.id !== candidate.id) {
        closedTurnIds = appendClosedTurn(closedTurnIds, state.activeTurn.id, state.config.closedTurnLimit);
    }
    const activeTurn = {
        id: candidate.id,
        originState: BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP,
        conversationContext: {
            mode: BROWSER_VOICE_CONTEXT_MODES.CONTEXTUAL_FOLLOW_UP,
            epoch: state.conversationEpoch,
        },
        segments: [],
        draft: text,
        finalizedDraft: final ? text : '',
        assessmentPending: false,
        submitted: false,
        clarificationQuestion: '',
        clarificationContinuation: false,
    };
    return {
        state: {
            ...state,
            conversationState: BROWSER_VOICE_CONVERSATION_STATES.CAPTURING,
            activeTurn,
            followUpCandidate: null,
            liveDraft: text,
            closedTurnIds,
            deadlines: { ...state.deadlines, followUpAt: null },
        },
        effects: [effect(BROWSER_VOICE_EFFECTS.DRAFT_CHANGED, {
            text,
            turnId: activeTurn.id,
            final,
        })],
    };
}

export function createBrowserVoiceControllerState(options = {}) {
    const config = Object.freeze({
        endpointMs: Math.max(0, Number(options.endpointMs ?? DEFAULT_CONFIG.endpointMs)),
        clarificationMs: Math.max(0, Number(options.clarificationMs ?? DEFAULT_CONFIG.clarificationMs)),
        followUpMs: Math.max(0, Number(options.followUpMs ?? DEFAULT_CONFIG.followUpMs)),
        closedTurnLimit: Math.max(1, Number(options.closedTurnLimit ?? DEFAULT_CONFIG.closedTurnLimit)),
    });
    return {
        conversationState: BROWSER_VOICE_CONVERSATION_STATES.OFF,
        generation: 0,
        connectionGeneration: 0,
        conversationEpoch: 0,
        lastSequences: {},
        activeTurn: null,
        followUpCandidate: null,
        liveDraft: '',
        finalizedTranscript: '',
        speechActive: false,
        closeAfterPlayback: false,
        recoveryState: null,
        failureReason: '',
        deadlines: { endpointAt: null, clarificationAt: null, followUpAt: null },
        closedTurnIds: [],
        rejectedEventCount: 0,
        lastRejectedEvent: null,
        config,
    };
}

/**
 * Pure browser conversation reducer. It owns capture and conversation timing,
 * but deliberately contains no durable work or request queue.
 */
export function reduceBrowserVoiceController(state, event) {
    if (!state || !event || typeof event.type !== 'string') {
        throw new TypeError('A voice controller state and event type are required.');
    }

    if (event.type === 'start') {
        const fresh = createBrowserVoiceControllerState(state.config);
        return {
            state: {
                ...fresh,
                conversationState: BROWSER_VOICE_CONVERSATION_STATES.STARTING,
                generation: state.generation + 1,
                connectionGeneration: state.connectionGeneration + 1,
            },
            effects: [effect(BROWSER_VOICE_EFFECTS.CANCEL_ALL_TIMERS)],
        };
    }

    if (event.type === 'disable') {
        const fresh = createBrowserVoiceControllerState(state.config);
        return {
            state: {
                ...fresh,
                generation: state.generation + 1,
                connectionGeneration: state.connectionGeneration + 1,
                closedTurnIds: state.activeTurn?.id
                    ? appendClosedTurn(state.closedTurnIds, state.activeTurn.id, state.config.closedTurnLimit)
                    : state.closedTurnIds,
            },
            effects: [
                effect(BROWSER_VOICE_EFFECTS.CANCEL_ALL_TIMERS),
                ...(state.followUpCandidate ? [effect(BROWSER_VOICE_EFFECTS.DISCARD_FOLLOW_UP_CANDIDATE, {
                    turnId: state.followUpCandidate.id,
                    providerItemId: state.followUpCandidate.providerItemId || null,
                    reason: event.reason || 'disabled',
                })] : []),
                effect(BROWSER_VOICE_EFFECTS.DRAFT_CHANGED, { text: '', turnId: null }),
                effect(BROWSER_VOICE_EFFECTS.STOP_PLAYBACK, { reason: event.reason || 'disabled' }),
            ],
        };
    }

    if (event.type === 'reconnect_started') {
        if (state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.OFF) {
            return rejectEvent(state, event, 'voice_off');
        }
        return {
            state: {
                ...state,
                conversationState: BROWSER_VOICE_CONVERSATION_STATES.RECOVERING_CONNECTION,
                connectionGeneration: state.connectionGeneration + 1,
                recoveryState: state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.RECOVERING_CONNECTION
                    ? state.recoveryState
                    : state.conversationState,
                speechActive: false,
            },
            effects: [effect(BROWSER_VOICE_EFFECTS.CANCEL_ALL_TIMERS)],
        };
    }

    if (event.type === 'stop_playback') {
        const awaitingClarification = state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION;
        const activeTurnId = state.activeTurn?.id || null;
        const candidate = state.followUpCandidate;
        const clarificationAt = awaitingClarification
            ? (Number.isFinite(event.atMs) ? event.atMs : 0) + state.config.clarificationMs
            : null;
        const next = {
            ...state,
            conversationState: awaitingClarification
                ? BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION
                : BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY,
            activeTurn: awaitingClarification ? state.activeTurn : null,
            followUpCandidate: null,
            liveDraft: awaitingClarification ? state.liveDraft : '',
            speechActive: false,
            closeAfterPlayback: false,
            deadlines: awaitingClarification
                ? { ...state.deadlines, endpointAt: null, clarificationAt, followUpAt: null }
                : { endpointAt: null, clarificationAt: null, followUpAt: null },
            closedTurnIds: !awaitingClarification && activeTurnId
                ? appendClosedTurn(state.closedTurnIds, activeTurnId, state.config.closedTurnLimit)
                : state.closedTurnIds,
        };
        if (!awaitingClarification && candidate?.id) {
            next.closedTurnIds = appendClosedTurn(next.closedTurnIds, candidate.id, state.config.closedTurnLimit);
        }
        return {
            state: next,
            effects: [
                effect(BROWSER_VOICE_EFFECTS.CANCEL_TIMER, { timerKey: BROWSER_VOICE_TIMER_KEYS.ENDPOINT }),
                ...(!awaitingClarification
                    ? [
                        effect(BROWSER_VOICE_EFFECTS.CANCEL_TIMER, { timerKey: BROWSER_VOICE_TIMER_KEYS.FOLLOW_UP }),
                        effect(BROWSER_VOICE_EFFECTS.DRAFT_CHANGED, { text: '', turnId: activeTurnId }),
                    ]
                    : [effect(BROWSER_VOICE_EFFECTS.SCHEDULE_TIMER, {
                        timerKey: BROWSER_VOICE_TIMER_KEYS.CLARIFICATION,
                        delayMs: state.config.clarificationMs,
                        turnId: activeTurnId,
                    })]),
                ...(!awaitingClarification && candidate ? [effect(BROWSER_VOICE_EFFECTS.DISCARD_FOLLOW_UP_CANDIDATE, {
                    turnId: candidate.id,
                    providerItemId: candidate.providerItemId || null,
                    reason: event.reason || 'user_stop',
                })] : []),
                effect(BROWSER_VOICE_EFFECTS.STOP_PLAYBACK, { reason: event.reason || 'user_stop' }),
            ],
        };
    }

    const scoped = !UNSCOPED_EVENT_TYPES.has(event.type);
    if (scoped) {
        if (event.generation !== state.generation) return rejectEvent(state, event, 'stale_generation');
        if (event.connectionGeneration !== state.connectionGeneration) {
            return rejectEvent(state, event, 'stale_connection_generation');
        }
        const source = cleanText(event.source) || 'external';
        if (!Number.isSafeInteger(event.sequence) || event.sequence <= (state.lastSequences[source] || 0)) {
            return rejectEvent(state, event, 'stale_sequence');
        }
        state = {
            ...state,
            lastSequences: { ...state.lastSequences, [source]: event.sequence },
        };

        const turnId = cleanText(event.turnId);
        if (turnId && state.closedTurnIds.includes(turnId) && !['playback_started', 'playback_finished'].includes(event.type)) {
            return rejectEvent(state, event, 'closed_turn');
        }
        if (TURN_SCOPED_EVENT_TYPES.has(event.type)) {
            const inputTurn = ['admission_clarification_required', 'admission_failed'].includes(event.type)
                ? state.activeTurn
                : state.followUpCandidate || state.activeTurn;
            if (!inputTurn) return rejectEvent(state, event, 'no_active_turn');
            if (!turnId || turnId !== inputTurn.id) return rejectEvent(state, event, 'turn_mismatch');
        }
    }

    const atMs = Number.isFinite(event.atMs) ? event.atMs : 0;

    switch (event.type) {
        case 'provider_ready': {
            if (![BROWSER_VOICE_CONVERSATION_STATES.STARTING, BROWSER_VOICE_CONVERSATION_STATES.RECOVERING_CONNECTION].includes(state.conversationState)) {
                return rejectEvent(state, event, 'unexpected_provider_ready');
            }
            const restoredState = state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.RECOVERING_CONNECTION
                ? state.recoveryState || BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY
                : BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY;
            const next = { ...state, conversationState: restoredState, recoveryState: null, failureReason: '' };
            return { state: next, effects: timerEffectsForState(next, atMs) };
        }

        case 'connection_lost': {
            if ([BROWSER_VOICE_CONVERSATION_STATES.OFF, BROWSER_VOICE_CONVERSATION_STATES.FAILED].includes(state.conversationState)) {
                return rejectEvent(state, event, 'connection_not_active');
            }
            const candidateRejected = state.followUpCandidate
                ? rejectFollowUpCandidate(state, event, atMs, 'connection_lost')
                : null;
            const resumableState = candidateRejected?.state || state;
            return {
                state: {
                    ...resumableState,
                    conversationState: BROWSER_VOICE_CONVERSATION_STATES.RECOVERING_CONNECTION,
                    recoveryState: resumableState.conversationState,
                    speechActive: false,
                },
                effects: [
                    ...(candidateRejected?.effects || []).filter((item) => item.type === BROWSER_VOICE_EFFECTS.DISCARD_FOLLOW_UP_CANDIDATE),
                    effect(BROWSER_VOICE_EFFECTS.CANCEL_ALL_TIMERS),
                ],
            };
        }

        case 'connection_failed': {
            const candidate = state.followUpCandidate;
            return {
                state: {
                    ...state,
                    conversationState: BROWSER_VOICE_CONVERSATION_STATES.FAILED,
                    followUpCandidate: null,
                    failureReason: cleanText(event.reason) || 'connection_failed',
                    speechActive: false,
                    deadlines: { endpointAt: null, clarificationAt: null, followUpAt: null },
                },
                effects: [
                    effect(BROWSER_VOICE_EFFECTS.CANCEL_ALL_TIMERS),
                    ...(candidate ? [effect(BROWSER_VOICE_EFFECTS.DISCARD_FOLLOW_UP_CANDIDATE, {
                        turnId: candidate.id,
                        providerItemId: candidate.providerItemId || null,
                        reason: 'connection_failed',
                    })] : []),
                    effect(BROWSER_VOICE_EFFECTS.STOP_PLAYBACK, { reason: cleanText(event.reason) || 'connection_failed' }),
                ],
            };
        }

        case 'wake_confirmed':
            if (![BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY, BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP,
                BROWSER_VOICE_CONVERSATION_STATES.CAPTURING, BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION,
                BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING].includes(state.conversationState)) {
                return rejectEvent(state, event, 'not_wake_ready');
            }
            // A strict wake always starts a new conversation, even if it
            // arrives while the prior turn's follow-up window is still open.
            return beginTurn(state, event, BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);

        case 'potential_barge_in':
            if (!state.speechActive) return rejectEvent(state, event, 'playback_not_active');
            return unchanged(state, [effect(BROWSER_VOICE_EFFECTS.DUCK_PLAYBACK, {
                reason: cleanText(event.reason) || 'potential_speech',
            })]);

        case 'barge_in_rejected':
            if (!state.speechActive) return rejectEvent(state, event, 'playback_not_active');
            return unchanged(state, [effect(BROWSER_VOICE_EFFECTS.RESTORE_PLAYBACK, {
                reason: cleanText(event.reason) || 'not_meaningful',
            })]);

        case 'barge_in_confirmed': {
            if (!state.speechActive) return rejectEvent(state, event, 'playback_not_active');
            if (state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION && state.activeTurn) {
                return {
                    state: {
                        ...state,
                        conversationState: BROWSER_VOICE_CONVERSATION_STATES.CAPTURING,
                        activeTurn: {
                            ...state.activeTurn,
                            draft: '',
                            finalizedDraft: '',
                            clarificationContinuation: true,
                        },
                        speechActive: false,
                        deadlines: { ...state.deadlines, clarificationAt: null, endpointAt: null },
                    },
                    effects: [
                        effect(BROWSER_VOICE_EFFECTS.CANCEL_TIMER, { timerKey: BROWSER_VOICE_TIMER_KEYS.CLARIFICATION }),
                        effect(BROWSER_VOICE_EFFECTS.CONFIRM_INTERRUPTION, { reason: 'meaningful_barge_in' }),
                    ],
                };
            }
            const started = beginTurn(state, { ...event, reason: 'meaningful_barge_in' },
                state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP
                    ? BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP
                    : BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY);
            return {
                state: started.state,
                effects: [
                    ...started.effects.filter((item) => item.type !== BROWSER_VOICE_EFFECTS.STOP_PLAYBACK),
                    effect(BROWSER_VOICE_EFFECTS.CONFIRM_INTERRUPTION, { reason: 'meaningful_barge_in' }),
                ],
            };
        }

        case 'activation_ready':
            if (state.conversationState !== BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING || !state.activeTurn) {
                return rejectEvent(state, event, 'not_activating');
            }
            if (cleanText(event.turnId) && cleanText(event.turnId) !== state.activeTurn.id) {
                return rejectEvent(state, event, 'turn_mismatch');
            }
            return unchanged({ ...state, conversationState: BROWSER_VOICE_CONVERSATION_STATES.CAPTURING });

        case 'speech_started': {
            if (state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY) {
                return rejectEvent(state, event, 'wake_required');
            }
            if (state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP
                && (!state.activeTurn || state.activeTurn.submitted)) {
                return beginFollowUpCandidate(state, event, atMs);
            }
            if (state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION && state.activeTurn) {
                if (cleanText(event.turnId) && cleanText(event.turnId) !== state.activeTurn.id) {
                    return rejectEvent(state, event, 'turn_mismatch');
                }
                return {
                    state: {
                        ...state,
                        conversationState: BROWSER_VOICE_CONVERSATION_STATES.CAPTURING,
                        activeTurn: { ...state.activeTurn, draft: '', finalizedDraft: '', clarificationContinuation: true },
                        deadlines: { ...state.deadlines, clarificationAt: null, endpointAt: null },
                    },
                    effects: [effect(BROWSER_VOICE_EFFECTS.CANCEL_TIMER, { timerKey: BROWSER_VOICE_TIMER_KEYS.CLARIFICATION })],
                };
            }
            if (state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING && state.activeTurn) {
                return unchanged({ ...state, conversationState: BROWSER_VOICE_CONVERSATION_STATES.CAPTURING });
            }
            if (state.conversationState !== BROWSER_VOICE_CONVERSATION_STATES.CAPTURING || !state.activeTurn) {
                return rejectEvent(state, event, 'not_capturing');
            }
            if (cleanText(event.turnId) && cleanText(event.turnId) !== state.activeTurn.id) {
                return rejectEvent(state, event, 'turn_mismatch');
            }
            return {
                state: { ...state, deadlines: { ...state.deadlines, endpointAt: null } },
                effects: [effect(BROWSER_VOICE_EFFECTS.CANCEL_TIMER, { timerKey: BROWSER_VOICE_TIMER_KEYS.ENDPOINT })],
            };
        }

        case 'transcript_partial':
        case 'transcript_final': {
            if (state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP && state.followUpCandidate) {
                const draft = cleanText(event.text);
                const final = event.type === 'transcript_final';
                const relevance = classifyBrowserVoiceFollowUpRelevance(draft, { final });
                if (relevance === BROWSER_VOICE_FOLLOW_UP_RELEVANCE.MEANINGFUL) {
                    return promoteFollowUpCandidate(state, draft, final);
                }
                if (relevance === BROWSER_VOICE_FOLLOW_UP_RELEVANCE.REJECTED) {
                    return rejectFollowUpCandidate(state, event, atMs, 'not_meaningful');
                }
                return unchanged({
                    ...state,
                    followUpCandidate: {
                        ...state.followUpCandidate,
                        draft,
                        finalizedDraft: final ? draft : state.followUpCandidate.finalizedDraft,
                        providerItemId: state.followUpCandidate.providerItemId || cleanText(event.providerItemId) || null,
                    },
                });
            }
            if (state.conversationState !== BROWSER_VOICE_CONVERSATION_STATES.CAPTURING || !state.activeTurn) {
                return rejectEvent(state, event, 'not_capturing');
            }
            const draft = cleanText(event.text);
            const activeTurn = {
                ...state.activeTurn,
                draft,
                finalizedDraft: event.type === 'transcript_final' ? draft : state.activeTurn.finalizedDraft,
            };
            const liveDraft = joinSegments(activeTurn.segments, draft);
            return {
                state: { ...state, activeTurn, liveDraft },
                effects: [effect(BROWSER_VOICE_EFFECTS.DRAFT_CHANGED, {
                    text: liveDraft,
                    turnId: activeTurn.id,
                    final: event.type === 'transcript_final',
                })],
            };
        }

        case 'speech_ended': {
            if (state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP && state.followUpCandidate) {
                const draft = cleanText(state.followUpCandidate.finalizedDraft || state.followUpCandidate.draft);
                const relevance = classifyBrowserVoiceFollowUpRelevance(draft, { final: true });
                if (relevance !== BROWSER_VOICE_FOLLOW_UP_RELEVANCE.MEANINGFUL) {
                    return rejectFollowUpCandidate(state, event, atMs, 'not_meaningful');
                }
                const promoted = promoteFollowUpCandidate(state, draft, true);
                const observedSilenceMs = Math.max(0, Number(event.observedSilenceMs) || 0);
                const remainingMs = Math.max(0, promoted.state.config.endpointMs - observedSilenceMs);
                const endpointAt = atMs + remainingMs;
                return {
                    state: {
                        ...promoted.state,
                        deadlines: { ...promoted.state.deadlines, endpointAt },
                    },
                    effects: [
                        ...promoted.effects,
                        effect(BROWSER_VOICE_EFFECTS.SCHEDULE_TIMER, {
                            timerKey: BROWSER_VOICE_TIMER_KEYS.ENDPOINT,
                            delayMs: remainingMs,
                            turnId: promoted.state.activeTurn.id,
                        }),
                    ],
                };
            }
            if (state.conversationState !== BROWSER_VOICE_CONVERSATION_STATES.CAPTURING || !state.activeTurn) {
                return rejectEvent(state, event, 'not_capturing');
            }
            if (!cleanText(state.activeTurn.draft)) return unchanged(state);
            const observedSilenceMs = Math.max(0, Number(event.observedSilenceMs) || 0);
            const remainingMs = Math.max(0, state.config.endpointMs - observedSilenceMs);
            const endpointAt = atMs + remainingMs;
            return {
                state: { ...state, deadlines: { ...state.deadlines, endpointAt } },
                effects: [effect(BROWSER_VOICE_EFFECTS.SCHEDULE_TIMER, {
                    timerKey: BROWSER_VOICE_TIMER_KEYS.ENDPOINT,
                    delayMs: remainingMs,
                    turnId: state.activeTurn.id,
                })],
            };
        }

        case 'capture_failed': {
            if (state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP && state.followUpCandidate) {
                return rejectFollowUpCandidate(state, event, atMs, cleanText(event.reason) || 'capture_failed');
            }
            if (state.conversationState !== BROWSER_VOICE_CONVERSATION_STATES.CAPTURING || !state.activeTurn) {
                return rejectEvent(state, event, 'not_capturing');
            }
            const turnId = state.activeTurn.id;
            return {
                state: {
                    ...state,
                    conversationState: BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP,
                    activeTurn: null,
                    liveDraft: '',
                    finalizedTranscript: '',
                    closedTurnIds: appendClosedTurn(state.closedTurnIds, turnId, state.config.closedTurnLimit),
                    deadlines: { endpointAt: null, clarificationAt: null, followUpAt: null },
                },
                effects: [
                    effect(BROWSER_VOICE_EFFECTS.CANCEL_TIMER, { timerKey: BROWSER_VOICE_TIMER_KEYS.ENDPOINT }),
                    effect(BROWSER_VOICE_EFFECTS.DRAFT_CHANGED, { text: '', turnId }),
                ],
            };
        }

        case 'follow_up_candidate_rejected':
            if (state.conversationState !== BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP || !state.followUpCandidate) {
                return rejectEvent(state, event, 'no_follow_up_candidate');
            }
            return rejectFollowUpCandidate(state, event, atMs, cleanText(event.reason) || 'not_meaningful');

        case 'admission_clarification_required': {
            if (state.conversationState !== BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP
                || !state.activeTurn?.submitted
                || !cleanText(event.question)) {
                return rejectEvent(state, event, 'admission_not_awaiting_clarification');
            }
            return {
                state: {
                    ...state,
                    conversationState: BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION,
                    activeTurn: {
                        ...state.activeTurn,
                        submitted: false,
                        clarificationQuestion: cleanText(event.question),
                        clarificationContinuation: false,
                    },
                    deadlines: { endpointAt: null, clarificationAt: null, followUpAt: null },
                },
                effects: [effect(BROWSER_VOICE_EFFECTS.SPEAK_CLARIFICATION, {
                    turnId: state.activeTurn.id,
                    text: cleanText(event.question),
                })],
            };
        }

        case 'admission_failed': {
            if (state.conversationState !== BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP
                || !state.activeTurn?.submitted) {
                return rejectEvent(state, event, 'admission_not_pending');
            }
            const turnId = state.activeTurn.id;
            const followUpAt = atMs + state.config.followUpMs;
            return {
                state: {
                    ...state,
                    activeTurn: null,
                    liveDraft: '',
                    closedTurnIds: appendClosedTurn(state.closedTurnIds, turnId, state.config.closedTurnLimit),
                    deadlines: { endpointAt: null, clarificationAt: null, followUpAt },
                },
                effects: [
                    effect(BROWSER_VOICE_EFFECTS.DRAFT_CHANGED, { text: '', turnId }),
                    effect(BROWSER_VOICE_EFFECTS.SCHEDULE_TIMER, {
                        timerKey: BROWSER_VOICE_TIMER_KEYS.FOLLOW_UP,
                        delayMs: state.config.followUpMs,
                        turnId: null,
                    }),
                ],
            };
        }

        case 'timer_fired': {
            const timerKey = cleanText(event.timerKey);
            if ([BROWSER_VOICE_TIMER_KEYS.ENDPOINT, BROWSER_VOICE_TIMER_KEYS.CLARIFICATION].includes(timerKey)) {
                if (!state.activeTurn) return rejectEvent(state, event, 'no_active_turn');
                if (!cleanText(event.turnId) || cleanText(event.turnId) !== state.activeTurn.id) {
                    return rejectEvent(state, event, 'turn_mismatch');
                }
            }
            if (timerKey === BROWSER_VOICE_TIMER_KEYS.ENDPOINT) {
                const deadline = state.deadlines.endpointAt;
                if (!Number.isFinite(deadline) || atMs < deadline) return rejectEvent(state, event, 'early_or_canceled_timer');
                const transcript = joinSegments(state.activeTurn.segments, state.activeTurn.draft);
                return {
                    state: {
                        ...state,
                        activeTurn: { ...state.activeTurn, assessmentPending: true },
                        deadlines: { ...state.deadlines, endpointAt: null },
                    },
                    effects: [effect(BROWSER_VOICE_EFFECTS.ASSESS_COMPLETENESS, {
                        turnId: state.activeTurn.id,
                        transcript,
                    })],
                };
            }
            if (timerKey === BROWSER_VOICE_TIMER_KEYS.CLARIFICATION) {
                const deadline = state.deadlines.clarificationAt;
                if (state.conversationState !== BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION
                    || !Number.isFinite(deadline) || atMs < deadline) {
                    return rejectEvent(state, event, 'early_or_canceled_timer');
                }
                const turn = state.activeTurn;
                const returnToFollowUp = turn?.originState === BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP;
                const followUpAt = returnToFollowUp ? atMs + state.config.followUpMs : null;
                return {
                    state: {
                        ...state,
                        conversationState: returnToFollowUp
                            ? BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP
                            : BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY,
                        activeTurn: null,
                        liveDraft: '',
                        closedTurnIds: turn?.id
                            ? appendClosedTurn(state.closedTurnIds, turn.id, state.config.closedTurnLimit)
                            : state.closedTurnIds,
                        deadlines: { endpointAt: null, clarificationAt: null, followUpAt },
                    },
                    effects: [
                        effect(BROWSER_VOICE_EFFECTS.DRAFT_CHANGED, { text: '', turnId: turn?.id || null }),
                        effect(BROWSER_VOICE_EFFECTS.CLARIFICATION_EXPIRED, { turnId: turn?.id || null }),
                        ...(returnToFollowUp ? [effect(BROWSER_VOICE_EFFECTS.SCHEDULE_TIMER, {
                            timerKey: BROWSER_VOICE_TIMER_KEYS.FOLLOW_UP,
                            delayMs: state.config.followUpMs,
                            turnId: null,
                        })] : []),
                    ],
                };
            }
            if (timerKey === BROWSER_VOICE_TIMER_KEYS.FOLLOW_UP) {
                const deadline = state.deadlines.followUpAt;
                if (state.conversationState !== BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP
                    || !Number.isFinite(deadline) || atMs < deadline) {
                    return rejectEvent(state, event, 'early_or_canceled_timer');
                }
                return {
                    state: {
                        ...state,
                        conversationState: BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY,
                        deadlines: { ...state.deadlines, followUpAt: null },
                    },
                    effects: [],
                };
            }
            return rejectEvent(state, event, 'unknown_timer');
        }

        case 'completeness_decided': {
            if (state.conversationState !== BROWSER_VOICE_CONVERSATION_STATES.CAPTURING
                || !state.activeTurn?.assessmentPending) {
                return rejectEvent(state, event, 'assessment_not_pending');
            }
            const decision = cleanText(event.decision).toLowerCase();
            const segment = cleanText(state.activeTurn.draft);
            const segments = segment ? [...state.activeTurn.segments, segment] : state.activeTurn.segments;
            if (decision === 'complete') {
                const transcript = joinSegments(segments);
                const activeTurn = {
                    ...state.activeTurn,
                    segments,
                    draft: '',
                    finalizedDraft: '',
                    assessmentPending: false,
                    submitted: true,
                    clarificationContinuation: false,
                };
                return {
                    state: {
                        ...state,
                        conversationState: BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP,
                        activeTurn,
                        liveDraft: '',
                        finalizedTranscript: transcript,
                    },
                    effects: [
                        effect(BROWSER_VOICE_EFFECTS.DRAFT_CHANGED, { text: '', turnId: activeTurn.id, final: true }),
                        effect(BROWSER_VOICE_EFFECTS.TURN_READY, {
                            turnId: activeTurn.id,
                            transcript,
                            conversationContext: { ...activeTurn.conversationContext },
                        }),
                    ],
                };
            }
            if (decision === 'incomplete' && cleanText(event.question)) {
                const activeTurn = {
                    ...state.activeTurn,
                    segments,
                    draft: '',
                    finalizedDraft: '',
                    assessmentPending: false,
                    clarificationQuestion: cleanText(event.question),
                    clarificationContinuation: false,
                };
                return {
                    state: {
                        ...state,
                        conversationState: BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION,
                        activeTurn,
                        liveDraft: joinSegments(segments),
                        deadlines: { ...state.deadlines, clarificationAt: null },
                    },
                    effects: [
                        effect(BROWSER_VOICE_EFFECTS.SPEAK_CLARIFICATION, {
                            turnId: activeTurn.id,
                            text: activeTurn.clarificationQuestion,
                        }),
                    ],
                };
            }
            // An uncertain pause, or an incomplete result without a safe
            // specific question, listens silently without admitting work. It
            // still has the clarification deadline so an abandoned fragment
            // cannot strand capture (and every deferred final) indefinitely.
            const activeTurn = {
                ...state.activeTurn,
                segments,
                draft: '',
                finalizedDraft: '',
                assessmentPending: false,
                clarificationContinuation: false,
            };
            const liveDraft = joinSegments(segments);
            const clarificationAt = atMs + state.config.clarificationMs;
            return {
                state: {
                    ...state,
                    conversationState: BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION,
                    activeTurn,
                    liveDraft,
                    deadlines: { ...state.deadlines, clarificationAt },
                },
                effects: [
                    effect(BROWSER_VOICE_EFFECTS.DRAFT_CHANGED, { text: liveDraft, turnId: activeTurn.id }),
                    effect(BROWSER_VOICE_EFFECTS.SCHEDULE_TIMER, {
                        timerKey: BROWSER_VOICE_TIMER_KEYS.CLARIFICATION,
                        delayMs: state.config.clarificationMs,
                        turnId: activeTurn.id,
                    }),
                ],
            };
        }

        case 'playback_started':
            return {
                state: {
                    ...state,
                    speechActive: true,
                    closeAfterPlayback: Boolean(event.naturalClosing),
                    deadlines: { ...state.deadlines, followUpAt: null },
                },
                effects: [effect(BROWSER_VOICE_EFFECTS.CANCEL_TIMER, { timerKey: BROWSER_VOICE_TIMER_KEYS.FOLLOW_UP })],
            };

        case 'playback_finished': {
            if ([BROWSER_VOICE_CONVERSATION_STATES.OFF, BROWSER_VOICE_CONVERSATION_STATES.STARTING,
                BROWSER_VOICE_CONVERSATION_STATES.FAILED, BROWSER_VOICE_CONVERSATION_STATES.RECOVERING_CONNECTION]
                .includes(state.conversationState)) return unchanged({ ...state, speechActive: false });
            if ([BROWSER_VOICE_CONVERSATION_STATES.ACTIVATING, BROWSER_VOICE_CONVERSATION_STATES.CAPTURING]
                .includes(state.conversationState)) {
                return unchanged({ ...state, speechActive: false, closeAfterPlayback: false });
            }
            if (state.followUpCandidate) {
                return unchanged({
                    ...state,
                    speechActive: false,
                    closeAfterPlayback: false,
                    deadlines: { ...state.deadlines, followUpAt: null },
                });
            }
            if (state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.AWAITING_CLARIFICATION) {
                const clarificationAt = atMs + state.config.clarificationMs;
                return {
                    state: {
                        ...state,
                        speechActive: false,
                        closeAfterPlayback: false,
                        deadlines: { ...state.deadlines, clarificationAt },
                    },
                    effects: [effect(BROWSER_VOICE_EFFECTS.SCHEDULE_TIMER, {
                        timerKey: BROWSER_VOICE_TIMER_KEYS.CLARIFICATION,
                        delayMs: state.config.clarificationMs,
                        turnId: state.activeTurn?.id || null,
                    })],
                };
            }
            const activeId = state.activeTurn?.id || null;
            const playbackTurnId = cleanText(event.turnId);
            const playbackOwnsActiveTurn = !playbackTurnId || !activeId || playbackTurnId === activeId;
            const closedTurnIds = playbackOwnsActiveTurn && state.activeTurn?.submitted
                ? appendClosedTurn(state.closedTurnIds, activeId, state.config.closedTurnLimit)
                : state.closedTurnIds;
            if (state.closeAfterPlayback || event.naturalClosing) {
                return {
                    state: {
                        ...state,
                        conversationState: BROWSER_VOICE_CONVERSATION_STATES.WAKE_ONLY,
                        activeTurn: playbackOwnsActiveTurn ? null : state.activeTurn,
                        speechActive: false,
                        closeAfterPlayback: false,
                        closedTurnIds,
                        deadlines: { ...state.deadlines, followUpAt: null },
                    },
                    effects: [effect(BROWSER_VOICE_EFFECTS.CANCEL_TIMER, { timerKey: BROWSER_VOICE_TIMER_KEYS.FOLLOW_UP })],
                };
            }
            const followUpAt = atMs + state.config.followUpMs;
            return {
                state: {
                    ...state,
                    conversationState: BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP,
                    activeTurn: playbackOwnsActiveTurn && state.activeTurn?.submitted ? null : state.activeTurn,
                    speechActive: false,
                    closeAfterPlayback: false,
                    closedTurnIds,
                    deadlines: { ...state.deadlines, followUpAt },
                },
                effects: [effect(BROWSER_VOICE_EFFECTS.SCHEDULE_TIMER, {
                    timerKey: BROWSER_VOICE_TIMER_KEYS.FOLLOW_UP,
                    delayMs: state.config.followUpMs,
                    turnId: null,
                })],
            };
        }

        default:
            return rejectEvent(state, event, 'unknown_event');
    }
}

function copyState(state) {
    return {
        ...state,
        lastSequences: { ...state.lastSequences },
        activeTurn: state.activeTurn
            ? {
                ...state.activeTurn,
                segments: [...state.activeTurn.segments],
                conversationContext: state.activeTurn.conversationContext
                    ? { ...state.activeTurn.conversationContext }
                    : null,
            }
            : null,
        followUpCandidate: state.followUpCandidate ? { ...state.followUpCandidate } : null,
        deadlines: { ...state.deadlines },
        closedTurnIds: [...state.closedTurnIds],
        config: { ...state.config },
        lastRejectedEvent: state.lastRejectedEvent ? { ...state.lastRejectedEvent } : null,
    };
}

export class BrowserVoiceControllerV2 {
    constructor({
        clock = () => Date.now(),
        timers = {},
        createTurnId = null,
        onEffect = null,
        onStateChange = null,
        speechScheduler = null,
        ...config
    } = {}) {
        this.clock = clock;
        this.setTimeout = timers.setTimeout || globalThis.setTimeout?.bind(globalThis);
        this.clearTimeout = timers.clearTimeout || globalThis.clearTimeout?.bind(globalThis);
        this.createTurnId = createTurnId;
        this.onEffect = onEffect;
        this.onStateChange = onStateChange;
        this.speechScheduler = speechScheduler;
        this.state = createBrowserVoiceControllerState(config);
        this.sequenceCounters = {};
        this.turnCounter = 0;
        this.timerHandles = new Map();
        this.effects = [];
    }

    snapshot() {
        return Object.freeze(copyState(this.state));
    }

    drainEffects() {
        return this.effects.splice(0);
    }

    dispatch(input) {
        const event = { ...input };
        if (!UNSCOPED_EVENT_TYPES.has(event.type)) {
            event.source = cleanText(event.source) || 'controller';
            if (!Number.isSafeInteger(event.sequence)) {
                event.sequence = (this.sequenceCounters[event.source] || 0) + 1;
            }
            this.sequenceCounters[event.source] = Math.max(this.sequenceCounters[event.source] || 0, event.sequence);
            event.generation ??= this.state.generation;
            event.connectionGeneration ??= this.state.connectionGeneration;
        }
        event.atMs ??= this.clock();

        const previous = this.state;
        const result = reduceBrowserVoiceController(previous, event);
        this.state = result.state;
        if (event.type === 'start' || event.type === 'disable') this.sequenceCounters = {};
        for (const emitted of result.effects) this.#applyEffect(emitted);
        if (this.onStateChange && this.state !== previous) this.onStateChange(this.snapshot(), event);
        return { state: this.snapshot(), effects: result.effects };
    }

    start() {
        return this.dispatch({ type: 'start' }).state;
    }

    providerReady(event = {}) {
        return this.dispatch({ type: 'provider_ready', ...event });
    }

    reconnect() {
        return this.dispatch({ type: 'reconnect_started' }).state.connectionGeneration;
    }

    disable(reason = 'disabled') {
        return this.dispatch({ type: 'disable', reason }).state;
    }

    wakeConfirmed({ turnId = '', ...event } = {}) {
        const playback = this.speechScheduler?.snapshot?.();
        return this.dispatch({
            type: 'wake_confirmed',
            turnId: cleanText(turnId) || this.#nextTurnId(),
            reason: 'wake',
            playbackOwned: Boolean(playback?.current || playback?.captureDeferredItemId),
            ...event,
        });
    }

    activationReady(event = {}) {
        return this.dispatch({ type: 'activation_ready', turnId: this.state.activeTurn?.id || null, ...event });
    }

    speechStarted({ turnId = '', ...event } = {}) {
        const submittedFollowUp = this.state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP
            && this.state.activeTurn?.submitted;
        const id = cleanText(turnId)
            || this.state.followUpCandidate?.id
            || (submittedFollowUp ? '' : this.state.activeTurn?.id)
            || (this.state.conversationState === BROWSER_VOICE_CONVERSATION_STATES.FOLLOW_UP ? this.#nextTurnId() : '');
        return this.dispatch({ type: 'speech_started', turnId: id || null, ...event });
    }

    transcriptPartial(text, event = {}) {
        return this.dispatch({
            type: 'transcript_partial',
            turnId: this.state.followUpCandidate?.id || this.state.activeTurn?.id || null,
            text,
            ...event,
        });
    }

    transcriptFinal(text, event = {}) {
        return this.dispatch({
            type: 'transcript_final',
            turnId: this.state.followUpCandidate?.id || this.state.activeTurn?.id || null,
            text,
            ...event,
        });
    }

    speechEnded(event = {}) {
        return this.dispatch({
            type: 'speech_ended',
            turnId: this.state.followUpCandidate?.id || this.state.activeTurn?.id || null,
            ...event,
        });
    }

    captureFailed(reason = 'transcription_failed', event = {}) {
        return this.dispatch({
            type: 'capture_failed',
            turnId: this.state.followUpCandidate?.id || this.state.activeTurn?.id || null,
            reason,
            ...event,
        });
    }

    rejectFollowUpCandidate(reason = 'not_meaningful', event = {}) {
        return this.dispatch({
            type: 'follow_up_candidate_rejected',
            turnId: this.state.followUpCandidate?.id || null,
            reason,
            ...event,
        });
    }

    admissionClarificationRequired(question, event = {}) {
        return this.dispatch({
            type: 'admission_clarification_required',
            turnId: this.state.activeTurn?.id || null,
            question,
            ...event,
        });
    }

    admissionFailed(turnId, reason = 'admission_failed', event = {}) {
        return this.dispatch({
            type: 'admission_failed',
            turnId: cleanText(turnId) || this.state.activeTurn?.id || null,
            reason,
            ...event,
        });
    }

    completenessDecided(decision, detail = {}) {
        return this.dispatch({
            type: 'completeness_decided',
            turnId: this.state.activeTurn?.id || null,
            decision,
            ...detail,
        });
    }

    playbackStarted(detail = {}) {
        return this.dispatch({ type: 'playback_started', ...detail });
    }

    playbackFinished(detail = {}) {
        return this.dispatch({ type: 'playback_finished', ...detail });
    }

    stopPlayback(reason = 'user_stop') {
        return this.dispatch({ type: 'stop_playback', reason });
    }

    potentialBargeIn(reason = 'potential_speech', event = {}) {
        return this.dispatch({ type: 'potential_barge_in', reason, ...event });
    }

    rejectBargeIn(reason = 'not_meaningful', event = {}) {
        return this.dispatch({ type: 'barge_in_rejected', reason, ...event });
    }

    confirmBargeIn({ turnId = '', ...event } = {}) {
        return this.dispatch({
            type: 'barge_in_confirmed',
            turnId: cleanText(turnId) || this.#nextTurnId(),
            ...event,
        });
    }

    #nextTurnId() {
        if (this.createTurnId) return String(this.createTurnId());
        this.turnCounter += 1;
        if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
        return `browser-voice-${this.state.generation}-${this.clock()}-${this.turnCounter}`;
    }

    #applyEffect(emitted) {
        this.effects.push(emitted);
        if (emitted.type === BROWSER_VOICE_EFFECTS.CANCEL_ALL_TIMERS) {
            for (const handle of this.timerHandles.values()) this.clearTimeout?.(handle);
            this.timerHandles.clear();
        } else if (emitted.type === BROWSER_VOICE_EFFECTS.CANCEL_TIMER) {
            const handle = this.timerHandles.get(emitted.timerKey);
            if (handle !== undefined) this.clearTimeout?.(handle);
            this.timerHandles.delete(emitted.timerKey);
        } else if (emitted.type === BROWSER_VOICE_EFFECTS.SCHEDULE_TIMER) {
            const previous = this.timerHandles.get(emitted.timerKey);
            if (previous !== undefined) this.clearTimeout?.(previous);
            const generation = this.state.generation;
            const connectionGeneration = this.state.connectionGeneration;
            const turnId = emitted.turnId;
            const handle = this.setTimeout?.(() => {
                if (this.timerHandles.get(emitted.timerKey) !== handle) return;
                this.timerHandles.delete(emitted.timerKey);
                this.dispatch({
                    type: 'timer_fired',
                    timerKey: emitted.timerKey,
                    turnId,
                    generation,
                    connectionGeneration,
                    source: `timer:${emitted.timerKey}`,
                });
            }, emitted.delayMs);
            if (handle !== undefined) this.timerHandles.set(emitted.timerKey, handle);
        } else if (emitted.type === BROWSER_VOICE_EFFECTS.STOP_PLAYBACK) {
            this.speechScheduler?.stopCurrent?.(emitted.reason);
        } else if (emitted.type === BROWSER_VOICE_EFFECTS.DUCK_PLAYBACK) {
            this.speechScheduler?.potentialInterruption?.(emitted.reason);
        } else if (emitted.type === BROWSER_VOICE_EFFECTS.RESTORE_PLAYBACK) {
            this.speechScheduler?.rejectInterruption?.(emitted.reason);
        } else if (emitted.type === BROWSER_VOICE_EFFECTS.CONFIRM_INTERRUPTION) {
            this.speechScheduler?.confirmInterruption?.(emitted.reason);
        }
        this.onEffect?.(emitted, this.snapshot());
    }
}
