export const BROWSER_VOICE_FOLLOW_UP_RELEVANCE = Object.freeze({
    PENDING: 'pending',
    MEANINGFUL: 'meaningful',
    REJECTED: 'rejected',
});

function normalizeFollowUpText(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/[’']/g, "'")
        .replace(/[^a-z0-9'\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * A deliberately closed, deterministic relevance grammar for the active
 * follow-up window. Unknown speech stays private until the final transcript,
 * then fails closed instead of becoming a Bean request.
 */
export function classifyBrowserVoiceFollowUpRelevance(text, { final = false } = {}) {
    const normalized = normalizeFollowUpText(text);
    if (!normalized) {
        return final
            ? BROWSER_VOICE_FOLLOW_UP_RELEVANCE.REJECTED
            : BROWSER_VOICE_FOLLOW_UP_RELEVANCE.PENDING;
    }

    if (/^hey bean(?:\b|$)/.test(normalized)) {
        return BROWSER_VOICE_FOLLOW_UP_RELEVANCE.MEANINGFUL;
    }

    if (/^(?:(?:um+|uh+|erm|hmm+|mm+)[, ]*)+(?:yeah|yep|yes|okay|ok|right|sure)?$/.test(normalized)) {
        return final
            ? BROWSER_VOICE_FOLLOW_UP_RELEVANCE.REJECTED
            : BROWSER_VOICE_FOLLOW_UP_RELEVANCE.PENDING;
    }

    const directAddress = normalized.match(/^bean\s+(.+)$/)?.[1] || normalized;
    const exactResponse = /^(?:yes|yep|no|nope|okay|ok|sure|please|thanks|thank you|that's all|that is all|goodbye|bye|take care|stop|quiet|wait|repeat that|say that again)$/;
    const directAction = /^(?:(?:also|and|then|actually|instead|please)\s+)*(?:tell|show|check|find|look up|read|create|add|make|set|schedule|remind|delete|remove|change|move|reschedule|cancel|complete|mark|plan|draft|write|save|send|call|text|help|repeat|say)\b/;
    const directQuestion = /^(?:what|what's|when|where|which|who|why|how)\s+(?:about\b|is\b|are\b|was\b|were\b|do\b|does\b|did\b|can\b|could\b|would\b|will\b|should\b|time\b|date\b|day\b|weather\b|temperature\b|comes?\b|happens?\b)/;
    const scopedStoredDataQuestion = /^(?:(?:what's|what is)\s+(?:on|in)\s+(?:my\s+)?|(?:anything|something)\s+(?:on|in)\s+(?:my\s+)?)(?:to[ -]?do\s+list|task\s+list|tasks?|calendar|schedule|reminders?|notes?)\b/;
    const assistantQuestion = /^(?:can|could|would|will|should)\s+you\s+(?:tell|show|check|find|look|read|create|add|make|set|schedule|remind|delete|remove|change|move|cancel|plan|draft|write|save|repeat|say|help|hear)\b/;
    const assistantStateQuestion = /^(?:do|did|are|is|have|were)\s+you\s+(?:hear|finish|finished|complete|completed|done|working|still|able|ready|listening|get|got|check|find)\b/;
    const scopedAuxiliaryQuestion = /^(?:(?:is|will|could|should)\s+it\b.*\b(?:rain|snow|storm|sunny|weather|forecast|temperature|done|finished|working|ready)\b|(?:do|did)\s+i\s+have\b|(?:is|are)\s+there\b.*\b(?:anything|event|appointment|meeting|reminder|task|note)\b|(?:did|has|is)\s+(?:it|that)\b.*\b(?:finish|finished|done|working|ready)\b)/;
    const firstPersonRequest = /^i\s+(?:need|want|would like|meant|asked|said)\b/;
    const contextualContinuation = /^(?:(?:and|also)\s+)?(?:what|how)\s+about\s+(?:today|tomorrow|tonight|later|the\s+(?:first|second|next|last)\s+one)\b|^(?:and|also)\s+(?:the\s+)?(?:time|date|weather|calendar|first|second|next|last)\b|^(?:the\s+)?(?:first|second|next|last)\s+one\b|^(?:today|tomorrow|tonight|later)(?:\s+(?:at|around|after|before)\s+(?:noon|midnight|\d{1,2}(?::\d{2})?\s*(?:a\s*m|p\s*m)?))?\??$|^(?:at|around|after|before)\s+(?:noon|midnight|\d{1,2}(?::\d{2})?\s*(?:a\s*m|p\s*m)?)\??$/;
    const explicitCorrection = /^(?:no\s+)?(?:wait|actually|instead|correction)\b|^(?:make|change|move|set)\s+(?:it|that)\b/;

    if (exactResponse.test(directAddress)
        || directAction.test(directAddress)
        || directQuestion.test(directAddress)
        || scopedStoredDataQuestion.test(directAddress)
        || assistantQuestion.test(directAddress)
        || assistantStateQuestion.test(directAddress)
        || scopedAuxiliaryQuestion.test(directAddress)
        || firstPersonRequest.test(directAddress)
        || contextualContinuation.test(directAddress)
        || explicitCorrection.test(directAddress)) {
        return BROWSER_VOICE_FOLLOW_UP_RELEVANCE.MEANINGFUL;
    }

    return final
        ? BROWSER_VOICE_FOLLOW_UP_RELEVANCE.REJECTED
        : BROWSER_VOICE_FOLLOW_UP_RELEVANCE.PENDING;
}
