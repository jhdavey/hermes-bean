<?php

namespace App\Data;

use App\Enums\VoiceTurnLane;

final readonly class VoiceTurnRoute
{
    public function __construct(
        public VoiceTurnLane $lane,
        public string $handler,
        public bool $acknowledgementRequired,
        public ?string $acknowledgementText,
        public int $hardDeadlineSeconds,
        public ?int $noProgressDeadlineSeconds = null,
    ) {}
}
