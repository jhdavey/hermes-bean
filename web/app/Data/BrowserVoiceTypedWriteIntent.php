<?php

namespace App\Data;

use Illuminate\Support\Carbon;

final readonly class BrowserVoiceTypedWriteIntent
{
    public function __construct(
        public string $resource,
        public ?string $title,
        public ?Carbon $scheduledAt,
        public bool $hasClockTime,
    ) {}

    public function clarificationQuestion(): ?string
    {
        if ($this->title === null) {
            return $this->resource === 'reminder'
                ? 'What should I remind you about?'
                : 'What should I schedule?';
        }

        if (! $this->hasClockTime || $this->scheduledAt === null) {
            return $this->resource === 'reminder'
                ? 'What time should I remind you?'
                : 'What time should I schedule it?';
        }

        return null;
    }

    public function isActionable(): bool
    {
        return $this->clarificationQuestion() === null;
    }
}
