export function withBenchmarkDeadline(promise, {
    timeoutMs,
    label,
    setTimeoutFn = globalThis.setTimeout,
    clearTimeoutFn = globalThis.clearTimeout,
} = {}) {
    const duration = Math.max(1, Number(timeoutMs) || 1);
    let timer = null;
    const deadline = new Promise((_, reject) => {
        timer = setTimeoutFn(() => {
            reject(new Error(`${String(label || 'Browser Voice benchmark step')} exceeded ${duration} ms.`));
        }, duration);
    });

    return Promise.race([Promise.resolve(promise), deadline])
        .finally(() => clearTimeoutFn(timer));
}
