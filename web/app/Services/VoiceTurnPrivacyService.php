<?php

namespace App\Services;

class VoiceTurnPrivacyService
{
    /** @var array<int, string> */
    private const RAW_AUDIO_KEYS = [
        'audio',
        'raw_audio',
        'microphone_audio',
        'audio_blob',
        'audio_bytes',
        'pcm',
        'pcm_data',
        'waveform',
        'recording',
    ];

    public function sanitizeTranscript(string $transcript): string
    {
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $transcript) ?? $transcript;
        $sanitized = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*\b/i', 'Bearer [redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b(?:sk|pk)-[A-Za-z0-9_-]{16,}\b/', '[redacted key]', $sanitized) ?? $sanitized;

        return mb_substr(trim($sanitized), 0, 12000);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitizeDiagnosticPayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if ($this->isRawAudioKey($normalizedKey)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeDiagnosticPayload($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->sanitizeTranscript($value);
            } elseif (is_scalar($value) || $value === null) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    public function isRawAudioKey(string $key): bool
    {
        $normalized = strtolower(trim($key));

        return in_array($normalized, self::RAW_AUDIO_KEYS, true)
            || str_ends_with($normalized, '_audio_blob')
            || str_ends_with($normalized, '_audio_bytes')
            || str_starts_with($normalized, 'raw_audio_');
    }
}
