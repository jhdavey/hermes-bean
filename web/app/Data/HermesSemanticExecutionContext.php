<?php

namespace App\Data;

use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceTurn;
use InvalidArgumentException;

final readonly class HermesSemanticExecutionContext
{
    public int $user_id;

    public int $workspace_id;

    public int $conversation_session_id;

    public string $turn_id;

    /** @var array<string, mixed> */
    public array $metadata;

    /** @param array<string, mixed> $clientContext */
    public function __construct(
        public User $user,
        public ConversationSession $session,
        public string $stableRequestId,
        public ?string $timezone,
        public array $clientContext = [],
        public ?VoiceTurn $voiceTurn = null,
    ) {
        if (! $this->user->exists || ! $this->user->getKey()) {
            throw new InvalidArgumentException('Semantic execution requires a persisted user.');
        }
        if (! $this->session->exists || ! $this->session->getKey()) {
            throw new InvalidArgumentException('Semantic execution requires a persisted conversation session.');
        }
        if ((int) $this->session->user_id !== (int) $this->user->getKey()
            || trim($this->stableRequestId) === '') {
            throw new InvalidArgumentException('Semantic execution context does not match its durable request.');
        }
        if ($this->timezone !== null) {
            try {
                new \DateTimeZone($this->timezone);
            } catch (\Throwable $exception) {
                throw new InvalidArgumentException(
                    'Semantic execution timezone must be a valid IANA timezone, explicit UTC offset, or null.',
                    previous: $exception,
                );
            }
        }

        $this->user_id = (int) $this->user->getKey();
        $this->workspace_id = (int) $this->session->workspace_id;
        $this->conversation_session_id = (int) $this->session->getKey();
        $this->turn_id = $this->stableRequestId;
        $this->metadata = [
            'timezone' => $this->timezone,
            'client_context' => $this->clientContext,
        ];
    }

    public function userId(): int
    {
        return (int) $this->user->getKey();
    }

    public function workspaceId(): int
    {
        return (int) $this->session->workspace_id;
    }
}
