export function voiceTextIsBackgroundAcknowledgement(text) {
    const normalized = normalizeVoiceText(text);
    if (!normalized) return false;
    return /\b(?:i(?:'|’)?ll|i will|let me|i(?:'|’)?m going to|i am going to|i(?:'|’)?m checking|i am checking)\b/.test(normalized)
        && /\b(?:check|look|pull|gather|find|work|handle|update|sync|start|do that|take care)\b/.test(normalized)
        && !voiceTextContainsConcreteResult(normalized);
}

export function voiceTextContainsConcreteResult(text) {
    const normalized = normalizeVoiceText(text);
    if (!normalized) return false;
    return /\b(?:you have|you ve got|you've got|there are|there is|i found|i created|i updated|i moved|i deleted|done|finished|completed|scheduled|added|removed|changed|starts|ends|at \d|today at|tomorrow at|degrees|percent)\b/.test(normalized);
}

export function voiceTurnNeedsCompletionWait({ quickReplyText = '', assistantContent = '', resultStatus = '' } = {}) {
    if (String(assistantContent || '').trim()) return false;
    const status = String(resultStatus || '').toLowerCase();
    if (['failed', 'cancelled', 'canceled', 'blocked'].includes(status)) return false;
    if (['queued', 'running', 'processing'].includes(status)) return true;
    return voiceTextIsBackgroundAcknowledgement(quickReplyText);
}

function normalizeVoiceText(text) {
    return String(text || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s'’]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}
