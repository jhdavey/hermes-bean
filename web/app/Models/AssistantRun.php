<?php

namespace App\Models;

use App\Enums\VoiceTurnLane;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AssistantRun extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = [
        'request_fingerprint',
        'execution_generation',
        'queued_at',
    ];

    protected $fillable = [
        'voice_turn_id',
        'user_id',
        'workspace_id',
        'conversation_session_id',
        'user_message_id',
        'assistant_message_id',
        'client_request_id',
        'request_fingerprint',
        'execution_generation',
        'source',
        'lane',
        'handler',
        'label',
        'priority',
        'resource_lock_key',
        'idempotency_key',
        'hard_deadline_at',
        'last_progress_at',
        'dispatch_requested_at',
        'status',
        'input',
        'metadata',
        'result',
        'error',
        'queued_at',
        'started_at',
        'completed_at',
        'cancelled_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (AssistantRun $run): void {
            if ($run->voice_turn_id === null) {
                if (trim((string) $run->client_request_id) === ''
                    || preg_match('/^[a-f0-9]{64}$/', (string) $run->request_fingerprint) !== 1) {
                    throw new LogicException('Generic assistant runs require a stable client request identity and fingerprint.');
                }
                if ((int) ($run->execution_generation ?? 0) !== 0) {
                    throw new LogicException('A generic assistant run must be admitted before its first execution claim.');
                }

                return;
            }

            $lane = trim((string) $run->lane);
            if ($lane === '' || VoiceTurnLane::tryFrom($lane) === null) {
                throw new LogicException('Browser voice runs require an explicit valid lane.');
            }
            if (trim((string) $run->handler) === '') {
                throw new LogicException('Browser voice runs require an explicit handler.');
            }
        });

        static::updating(function (AssistantRun $run): void {
            if ($run->getOriginal('voice_turn_id') === null) {
                foreach (['client_request_id', 'request_fingerprint'] as $attribute) {
                    if ($run->isDirty($attribute)) {
                        throw new LogicException("Generic assistant run {$attribute} is immutable after admission.");
                    }
                }

                $originalGeneration = (int) $run->getOriginal('execution_generation');
                $nextGeneration = (int) $run->execution_generation;
                $claimsExecution = $run->getOriginal('status') === 'queued' && $run->status === 'running';
                if ($claimsExecution && $nextGeneration !== $originalGeneration + 1) {
                    throw new LogicException('A generic assistant run must advance its execution generation when claimed.');
                }
                if ($run->isDirty('execution_generation') && ! $claimsExecution) {
                    throw new LogicException('A generic assistant run execution generation may change only when it is claimed.');
                }

                return;
            }

            foreach (['voice_turn_id', 'lane', 'handler', 'idempotency_key'] as $attribute) {
                if ($run->isDirty($attribute)) {
                    throw new LogicException("Browser voice run {$attribute} is immutable after admission.");
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'result' => 'array',
            'execution_generation' => 'integer',
            'priority' => 'integer',
            'hard_deadline_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'dispatch_requested_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'conversation_session_id');
    }

    public function voiceTurn(): BelongsTo
    {
        return $this->belongsTo(VoiceTurn::class);
    }

    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'user_message_id');
    }

    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'assistant_message_id');
    }
}
