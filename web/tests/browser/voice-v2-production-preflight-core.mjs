export function browserVoiceV2ShellCheck(html) {
    const value = String(html || '').match(/data-browser-voice-v2=["'](true|false)["']/i)?.[1]?.toLowerCase() || null;

    return {
        actual: value,
        expected: 'data-browser-voice-v2="true"',
        pass: value === 'true',
    };
}
