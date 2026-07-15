export function browserVoiceV2ShellCheck(html) {
    const value = String(html || '').match(/data-browser-voice-v2=["'](true|false)["']/i)?.[1]?.toLowerCase() || null;

    return {
        actual: value,
        expected: 'data-browser-voice-v2="true"',
        pass: value === 'true',
    };
}

export function browserVoiceV2BundleCheck(source) {
    const bundle = String(source || '');
    const required = [
        '/assistant/voice/realtime/session',
        '/assistant/voice/client-failures',
        '/assistant/voice/turns',
        '/assistant/voice/state',
        '/assistant/voice/stream',
        '/assistant/voice/cancellations',
    ];
    const forbidden = [
        '/assistant/voice/realtime/usage',
        '/assistant/voice/speech',
    ];
    const missing = required.filter((marker) => !bundle.includes(marker));
    const legacy = forbidden.filter((marker) => bundle.includes(marker));

    return {
        actual: { missing_markers: missing, legacy_markers: legacy },
        expected: 'audio-native Realtime routes present and legacy transcription/TTS routes absent',
        pass: missing.length === 0 && legacy.length === 0,
    };
}
