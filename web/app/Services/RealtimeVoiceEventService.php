<?php

namespace App\Services;

use App\Exceptions\VoiceRealtimeLedgerException;
use App\Models\VoiceRealtimeEvent;
use App\Models\VoiceRealtimeSession;
use Illuminate\Support\Facades\DB;

class RealtimeVoiceEventService
{
    public function __construct(
        private readonly VoiceTurnPrivacyService $privacy,
    ) {}

    /**
     * @param  array<string, mixed>  $providerEvent
     * @return array{event: VoiceRealtimeEvent, created: bool}
     */
    public function record(VoiceRealtimeSession $session, array $providerEvent): array
    {
        $eventId = $this->identifier($providerEvent['event_id'] ?? null, 'provider event');
        $eventType = $this->identifier($providerEvent['type'] ?? null, 'provider event type', 120);
        $payload = $this->sanitizedPayload($providerEvent, $eventType);
        $providerInputItemId = $this->nullableIdentifier(
            data_get($providerEvent, 'item.id') ?? ($providerEvent['item_id'] ?? null),
        );
        $providerResponseId = $this->nullableIdentifier(
            data_get($providerEvent, 'response.id') ?? ($providerEvent['response_id'] ?? null),
        );

        $event = VoiceRealtimeEvent::query()->firstOrCreate(
            [
                'voice_realtime_session_id' => $session->id,
                'provider_event_id' => $eventId,
            ],
            [
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'conversation_session_id' => $session->conversation_session_id,
                'event_type' => $eventType,
                'provider_input_item_id' => $providerInputItemId,
                'provider_response_id' => $providerResponseId,
                'payload' => $payload,
                'received_at' => now(),
            ],
        );

        if (! $event->wasRecentlyCreated) {
            $sameScope = (int) $event->user_id === (int) $session->user_id
                && (int) $event->conversation_session_id === (int) $session->conversation_session_id
                && (int) ($event->workspace_id ?? 0) === (int) ($session->workspace_id ?? 0);
            $sameProviderIdentity = $event->provider_input_item_id === $providerInputItemId
                && $event->provider_response_id === $providerResponseId;
            if (! $sameScope
                || ! $sameProviderIdentity
                || $event->event_type !== $eventType
                || ! hash_equals($this->payloadHash($event->payload ?? []), $this->payloadHash($payload))) {
                throw new VoiceRealtimeLedgerException('A provider event identifier was reused with conflicting data.');
            }
        }

        return ['event' => $event, 'created' => $event->wasRecentlyCreated];
    }

    public function markProcessed(VoiceRealtimeEvent $event): VoiceRealtimeEvent
    {
        $event->forceFill([
            'processed_at' => $event->processed_at ?? now(),
            'processing_lease_owner' => null,
            'processing_started_at' => null,
            'next_attempt_at' => null,
            'failed_at' => null,
            'error' => null,
        ])->save();

        return $event->refresh();
    }

    /** Preserve the original failure evidence after lifecycle reconciliation succeeds. */
    public function markFailureReconciled(VoiceRealtimeEvent $event): VoiceRealtimeEvent
    {
        $event->forceFill([
            'processed_at' => $event->processed_at ?? now(),
            'processing_lease_owner' => null,
            'processing_started_at' => null,
            'next_attempt_at' => null,
        ])->save();

        return $event->refresh();
    }

    public function markFailed(VoiceRealtimeEvent $event, string $error): VoiceRealtimeEvent
    {
        $attempt = max(1, (int) $event->processing_attempts);
        $baseDelayMs = max(0, (int) config('services.voice_realtime.event_retry_delay_ms', 100));
        $delayMs = min(5000, $baseDelayMs * (2 ** min(5, max(0, $attempt - 1))));
        $event->forceFill([
            'failed_at' => now(),
            'processing_lease_owner' => null,
            'processing_started_at' => null,
            // An exhausted event is retried through terminal failure
            // reconciliation, not through the application event handler.
            'next_attempt_at' => now()->addMilliseconds($delayMs),
            'error' => $this->privacy->sanitizeTranscript($error),
        ])->save();

        return $event->refresh();
    }

    public function markReconciliationFailed(VoiceRealtimeEvent $event, string $error): VoiceRealtimeEvent
    {
        $baseDelayMs = max(10, (int) config('services.voice_realtime.event_retry_delay_ms', 100));
        $event->forceFill([
            'processing_lease_owner' => null,
            'processing_started_at' => null,
            'next_attempt_at' => now()->addMilliseconds(min(5000, $baseDelayMs * 4)),
            'error' => $event->error ?: $this->privacy->sanitizeTranscript($error),
        ])->save();

        return $event->refresh();
    }

    public function isExhausted(VoiceRealtimeEvent $event): bool
    {
        return $event->failed_at !== null
            && $event->processing_attempts >= max(
                1,
                (int) config('services.voice_realtime.event_max_attempts', 3),
            );
    }

    /**
     * Claim the oldest unprocessed provider event under the same durable
     * sideband lease that owns its session. A new daemon identity may take
     * over an event left in-flight by a crashed process; the provider event ID
     * and downstream ledgers keep replay idempotent.
     */
    public function claimNext(VoiceRealtimeSession $session, string $leaseOwner): ?VoiceRealtimeEvent
    {
        $owner = trim($leaseOwner);
        if ($owner === '') {
            throw new VoiceRealtimeLedgerException('A provider event processing lease owner is required.');
        }

        return DB::transaction(function () use ($session, $owner): ?VoiceRealtimeEvent {
            $lockedSession = VoiceRealtimeSession::query()->lockForUpdate()->find($session->id);
            if (! $lockedSession instanceof VoiceRealtimeSession
                || $lockedSession->status->value !== 'ready'
                || $lockedSession->lease_owner === null
                || ! hash_equals($lockedSession->lease_owner, $owner)
                || $lockedSession->lease_expires_at === null
                || ! $lockedSession->lease_expires_at->isFuture()) {
                return null;
            }

            $event = VoiceRealtimeEvent::query()
                ->where('voice_realtime_session_id', $lockedSession->id)
                ->whereNull('processed_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if (! $event instanceof VoiceRealtimeEvent) {
                return null;
            }

            $maxAttempts = max(1, (int) config('services.voice_realtime.event_max_attempts', 3));
            if (($event->next_attempt_at !== null && $event->next_attempt_at->isFuture())
                || ($event->processing_lease_owner !== null
                    && hash_equals($event->processing_lease_owner, $owner))) {
                return null;
            }

            $exhausted = $event->processing_attempts >= $maxAttempts;
            $event->forceFill([
                'processing_attempts' => $exhausted
                    ? $event->processing_attempts
                    : $event->processing_attempts + 1,
                'processing_lease_owner' => $owner,
                'processing_started_at' => now(),
                'next_attempt_at' => null,
                'failed_at' => $exhausted ? $event->failed_at : null,
                'error' => $exhausted ? $event->error : null,
            ])->save();

            return $event->refresh();
        });
    }

    /** @param array<string, mixed> $payload */
    private function sanitizedPayload(array $payload, string $eventType): array
    {
        $stripDelta = str_contains($eventType, 'audio') || str_contains($eventType, 'buffer.append');

        return $this->privacy->sanitizeDiagnosticPayload(
            $this->stripProviderBinary($payload, $stripDelta),
        );
    }

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array<string|int, mixed>
     */
    private function stripProviderBinary(array $payload, bool $stripDelta): array
    {
        $safe = [];
        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            if ($this->privacy->isRawAudioKey($normalized)
                || ($stripDelta && in_array($normalized, ['delta', 'chunk', 'bytes'], true))) {
                continue;
            }

            $safe[$key] = is_array($value)
                ? $this->stripProviderBinary($value, $stripDelta)
                : $value;
        }

        return $safe;
    }

    private function identifier(mixed $value, string $label, int $maxLength = 191): string
    {
        $identifier = is_string($value) ? trim($value) : '';
        if ($identifier === '' || mb_strlen($identifier) > $maxLength) {
            throw new VoiceRealtimeLedgerException("The {$label} identifier is invalid.");
        }

        return $identifier;
    }

    private function nullableIdentifier(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, 191);
    }

    /** @param array<string|int, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    /** @return array<string|int, mixed> */
    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->canonicalize($item) : $item,
                $value,
            );
        }

        ksort($value);

        return array_map(
            fn (mixed $item): mixed => is_array($item) ? $this->canonicalize($item) : $item,
            $value,
        );
    }
}
