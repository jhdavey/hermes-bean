<?php

namespace App\Services;

use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use Illuminate\Support\Carbon;

/**
 * Authorizes whether the current browser conversation epoch may expose a
 * prior turn to Hermes. It never interprets a reference or selects a target.
 */
final class BrowserVoiceContextAuthorizationService
{
    private const FOLLOW_UP_WINDOW_SECONDS = 15;

    /**
     * @param  array<string, mixed>  $input
     */
    public function priorTurn(User $user, ConversationSession $session, array $input): ?VoiceTurn
    {
        if (data_get($input, 'conversation_context.mode') !== 'contextual_follow_up') {
            return null;
        }

        $epoch = (int) data_get($input, 'conversation_context.epoch', -1);
        $generation = (int) ($input['controller_generation'] ?? -1);
        if ($epoch < 1 || $generation < 0) {
            return null;
        }

        $turnId = trim((string) ($input['turn_id'] ?? ''));

        $candidate = VoiceTurn::query()
            ->where('user_id', $user->id)
            ->where('conversation_session_id', $session->id)
            ->when($turnId !== '', fn ($query) => $query->where('turn_id', '!=', $turnId))
            ->latest('id')
            ->first();

        if (! $candidate instanceof VoiceTurn
            || (int) data_get($candidate->metadata, 'conversation_context.epoch', -2) !== $epoch
            || (int) data_get($candidate->metadata, 'controller_generation', -2) !== $generation) {
            return null;
        }

        $windowOpenedAt = $this->followUpWindowOpenedAt($candidate, $generation);
        $now = now();
        if (! $windowOpenedAt instanceof Carbon
            || $windowOpenedAt->isAfter($now)
            || $windowOpenedAt->lt($now->copy()->subSeconds(self::FOLLOW_UP_WINDOW_SECONDS))) {
            return null;
        }

        return $candidate;
    }

    /**
     * Use server-created browser playback events, never client clocks, to open
     * the follow-up window. The first terminal event for a speech item wins so
     * a duplicated delivery callback cannot extend authorization indefinitely.
     */
    private function followUpWindowOpenedAt(VoiceTurn $turn, int $generation): ?Carbon
    {
        $firstEventBySpeechItem = [];
        $events = VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->where('source', 'browser')
            ->whereIn('event_type', ['playback_finished', 'playback_stopped'])
            ->orderBy('id')
            ->get(['id', 'created_at', 'payload']);

        foreach ($events as $event) {
            $eventGeneration = data_get($event->payload, 'controller_generation');
            if ($eventGeneration !== null && (int) $eventGeneration !== $generation) {
                continue;
            }

            $speechItemId = trim((string) data_get($event->payload, 'speech_item_id', ''));
            $purpose = trim((string) data_get($event->payload, 'purpose', ''));
            $directiveId = trim((string) data_get($event->payload, 'directive_id', ''));
            if ($purpose !== 'final' && $directiveId === '') {
                continue;
            }
            $key = $speechItemId !== '' ? 'speech:'.$speechItemId : 'purpose:'.($purpose ?: 'unspecified');
            if (! isset($firstEventBySpeechItem[$key]) && $event->created_at instanceof Carbon) {
                $firstEventBySpeechItem[$key] = $event->created_at;
            }
        }

        if ($firstEventBySpeechItem === []) {
            return null;
        }

        return collect($firstEventBySpeechItem)
            ->sortByDesc(fn (Carbon $openedAt): int => $openedAt->getTimestamp())
            ->first();
    }
}
