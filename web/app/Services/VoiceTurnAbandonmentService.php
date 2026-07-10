<?php

namespace App\Services;

use App\Models\ConversationMessage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class VoiceTurnAbandonmentService
{
    public const OUTCOME = 'abandoned';

    public const REASON = 'terminal_update_missing_after_server_deadline';

    private const DEFAULT_LIMIT = 500;

    private const MAX_LIMIT = 5_000;

    /**
     * Mark stale, accepted direct/status turns as abandoned without inventing an
     * assistant response. The row lock shares the same serialization boundary as
     * AssistantVoiceController, so whichever terminal outcome wins first is final.
     *
     * @return array{candidate_count:int,examined_count:int,abandoned_count:int,skipped_count:int,abandon_after_seconds:int,cutoff:string}
     */
    public function reconcile(?CarbonInterface $asOf = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $now = $asOf instanceof CarbonInterface
            ? CarbonImmutable::instance($asOf)
            : CarbonImmutable::now();
        $abandonAfterSeconds = $this->abandonAfterSeconds();
        $cutoff = $now->subSeconds($abandonAfterSeconds);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $candidateIds = ConversationMessage::query()
            ->where('role', 'user')
            ->whereNotNull('client_turn_id')
            ->where('created_at', '<=', $cutoff)
            ->where('metadata->source', 'openai_realtime_voice')
            ->where('metadata->voice_turn_outcome->status', 'accepted')
            ->whereIn('metadata->voice_quality->route', ['direct', 'status'])
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $abandoned = 0;
        $examined = 0;

        foreach ($candidateIds as $candidateId) {
            $examined++;
            $changed = DB::transaction(function () use ($candidateId, $cutoff, $now, $abandonAfterSeconds): bool {
                $message = ConversationMessage::query()
                    ->whereKey($candidateId)
                    ->lockForUpdate()
                    ->first();

                if (! $message instanceof ConversationMessage || ! $this->isEligible($message, $cutoff)) {
                    return false;
                }

                $assistantExists = ConversationMessage::query()
                    ->where('conversation_session_id', $message->conversation_session_id)
                    ->where('client_turn_id', $message->client_turn_id)
                    ->where('role', 'assistant')
                    ->exists();
                if ($assistantExists) {
                    return false;
                }

                $metadata = is_array($message->metadata) ? $message->metadata : [];
                $currentLifecycle = is_array(data_get($metadata, 'voice_turn_outcome'))
                    ? data_get($metadata, 'voice_turn_outcome')
                    : [];
                $terminalAt = $now->toIso8601String();
                $metadata['voice_turn_outcome'] = array_merge($currentLifecycle, [
                    'status' => self::OUTCOME,
                    'accepted_at' => $currentLifecycle['accepted_at'] ?? $message->created_at?->toIso8601String() ?? $terminalAt,
                    'updated_at' => $terminalAt,
                    'terminal_at' => $terminalAt,
                    'reason' => self::REASON,
                    'classified_by' => 'server_stale_accepted_turn_reconciler',
                    'abandon_after_seconds' => $abandonAfterSeconds,
                ]);
                $message->forceFill(['metadata' => $metadata])->save();

                return true;
            }, 3);

            if ($changed) {
                $abandoned++;
            }
        }

        return [
            'candidate_count' => $candidateIds->count(),
            'examined_count' => $examined,
            'abandoned_count' => $abandoned,
            'skipped_count' => $examined - $abandoned,
            'abandon_after_seconds' => $abandonAfterSeconds,
            'cutoff' => $cutoff->toIso8601String(),
        ];
    }

    public function abandonAfterSeconds(): int
    {
        // The browser watchdog plus two bounded persistence attempts can legitimately
        // take tens of seconds. Keep the server deadline comfortably beyond that path.
        return max(60, (int) config('services.openai.realtime_turn_abandon_after_seconds', 120));
    }

    private function isEligible(ConversationMessage $message, CarbonImmutable $cutoff): bool
    {
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if ($message->role !== 'user'
            || trim((string) $message->client_turn_id) === ''
            || data_get($metadata, 'source') !== 'openai_realtime_voice'
            || strtolower(trim((string) data_get($metadata, 'voice_turn_outcome.status'))) !== 'accepted'
            || ! in_array(strtolower(trim((string) data_get($metadata, 'voice_quality.route'))), ['direct', 'status'], true)) {
            return false;
        }

        $acceptedAt = $message->created_at?->toImmutable();
        $recordedAcceptedAt = data_get($metadata, 'voice_turn_outcome.accepted_at');
        if (is_string($recordedAcceptedAt) && trim($recordedAcceptedAt) !== '') {
            try {
                $acceptedAt = CarbonImmutable::parse($recordedAcceptedAt);
            } catch (\Throwable) {
                // Fall back to the durable message timestamp for malformed legacy metadata.
            }
        }

        return $acceptedAt instanceof CarbonImmutable && $acceptedAt->lte($cutoff);
    }
}
