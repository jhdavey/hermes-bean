import { BROWSER_VOICE_CONVERSATION_STATES } from './browserVoiceControllerV2.js';

export const BROWSER_VOICE_REALTIME_INGRESS_RESULTS = Object.freeze({
    ROUTED: 'routed',
    POTENTIAL_BARGE_IN: 'potential_barge_in',
    IGNORED: 'ignored',
});

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
