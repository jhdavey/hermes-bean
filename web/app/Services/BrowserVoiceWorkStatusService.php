<?php

namespace App\Services;

use App\Enums\VoiceTurnState;
use App\Models\VoiceTurn;

class BrowserVoiceWorkStatusService
{
    public function __construct(
        private readonly BrowserVoiceWorkReferenceResolver $references,
    ) {}

    public function answer(VoiceTurn $requestTurn): string
    {
        $target = $this->references->resolve($requestTurn);
        if (! $target instanceof VoiceTurn) {
            return 'I don’t have a recent request to check.';
        }

        $label = $this->label($target);

        return match ($target->state) {
            VoiceTurnState::Capturing, VoiceTurnState::AwaitingClarification => "I’m still waiting for the rest of the {$label}.",
            VoiceTurnState::Accepted => "The {$label} is queued and hasn’t started yet.",
            VoiceTurnState::Running => "I’m still working on the {$label}.",
            VoiceTurnState::Completed => "Yes—I finished the {$label}.",
            VoiceTurnState::Failed => "The {$label} failed. Would you like me to try again?",
            VoiceTurnState::Canceled => "The {$label} was canceled. Would you like me to restart it?",
        };
    }

    private function label(VoiceTurn $turn): string
    {
        if (str_contains($turn->handler, '.reminder.')) {
            return 'reminder request';
        }
        if (str_contains($turn->handler, '.task.')) {
            return 'task request';
        }
        if (str_contains($turn->handler, '.note.') || preg_match('/\bnotes?\b/iu', $turn->transcript) === 1) {
            return 'note request';
        }
        if (str_contains($turn->handler, '.calendar.') || preg_match('/\b(?:calendar|event|meeting|appointment)\b/iu', $turn->transcript) === 1) {
            return 'calendar request';
        }
        if ($turn->handler === 'external.weather') {
            return 'weather request';
        }

        return 'request';
    }
}
