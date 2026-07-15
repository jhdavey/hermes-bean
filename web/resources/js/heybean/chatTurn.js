export function stageOptimisticUserTurn(messages, {
    content,
    clientRequestId,
    localId,
} = {}) {
    const optimisticMessage = {
        id: String(localId || `local-${Date.now()}`),
        role: 'user',
        content: String(content || ''),
        metadata: { client_request_id: String(clientRequestId || '').trim() },
    };
    return {
        messages: [...(Array.isArray(messages) ? messages : []), optimisticMessage],
        optimisticMessage,
    };
}
