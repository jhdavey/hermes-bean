<?php

namespace App\Services;

use App\Exceptions\VoiceTurnConflictException;
use App\Models\VoiceTurn;
use Illuminate\Support\Carbon;

class BrowserVoiceInstantHandler
{
    public function answer(VoiceTurn $turn): string
    {
        $timezone = (string) data_get($turn->metadata, 'timezone', config('app.timezone', 'UTC'));
        try {
            $now = Carbon::now($timezone);
        } catch (\Throwable) {
            $now = Carbon::now(config('app.timezone', 'UTC'));
        }

        return match ($turn->handler) {
            'instant.current_time' => $this->currentTimeAnswer($now),
            'instant.current_date' => "Today is {$now->format('l, F jS')}.",
            'instant.voice_state' => 'Yes—I can hear you.',
            'instant.capability' => 'Yes—I can help with that.',
            'instant.confirmation_required' => 'I can do that if you want. Tell me directly to make the change.',
            'instant.conversation_close' => 'You’re welcome—take care.',
            default => throw new VoiceTurnConflictException('Unsupported immutable instant handler.'),
        };
    }

    private function spokenTime(Carbon $time): string
    {
        $period = $time->hour < 12 ? 'a.m.' : 'p.m.';
        if ($time->minute === 0) {
            return match ($time->hour) {
                0 => 'twelve a.m.',
                12 => 'twelve p.m.',
                default => ($time->hour % 12).' o’clock',
            };
        }

        return $time->format('g:i').' '.$period;
    }

    private function currentTimeAnswer(Carbon $time): string
    {
        $spoken = $this->spokenTime($time);

        return 'It’s '.$spoken.(str_ends_with($spoken, '.') ? '' : '.');
    }
}
