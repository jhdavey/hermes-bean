<?php

namespace App\Data;

use App\Models\User;
use InvalidArgumentException;
use JsonException;

final readonly class HermesSemanticInterpretationRequest
{
    /**
     * @param  array<string, mixed>  $context  Bounded durable conversation context and authorized resource snapshots.
     */
    public function __construct(
        public User $user,
        public ?int $workspaceId,
        public string $stableTurnId,
        public string $transcript,
        public string $currentTime,
        public ?string $timezone,
        public array $context = [],
        public string $locale = 'en-US',
        public ?int $conversationSessionId = null,
        public ?int $conversationMessageId = null,
    ) {
        if (! $this->user->exists || ! $this->user->getKey()) {
            throw new InvalidArgumentException('Semantic interpretation requires a persisted user for usage enforcement.');
        }

        foreach ([
            'stable turn id' => $this->stableTurnId,
            'transcript' => $this->transcript,
            'current time' => $this->currentTime,
            'locale' => $this->locale,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Semantic interpretation {$label} may not be empty.");
            }
        }

        if ($this->timezone !== null) {
            try {
                new \DateTimeZone($this->timezone);
            } catch (\Throwable $exception) {
                throw new InvalidArgumentException(
                    'Semantic interpretation timezone must be a valid IANA timezone, explicit UTC offset, or null.',
                    previous: $exception,
                );
            }
        }

        try {
            json_encode($this->context, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Semantic interpretation context must be JSON encodable.', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'transcript' => $this->transcript,
            'current_time' => $this->currentTime,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'context' => $this->context,
        ];
    }
}
