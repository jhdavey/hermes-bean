<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    protected $fillable = ['user_id', 'conversation_session_id', 'role', 'content', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected function content(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value, array $attributes): ?string => static::safeAssistantDisplayContent(
                $value,
                (string) ($attributes['role'] ?? ''),
            ),
        );
    }

    public static function safeAssistantDisplayContent(?string $content, string $role): ?string
    {
        if ($content === null || $role !== 'assistant') {
            return $content;
        }

        $trimmed = trim($content);
        if ($trimmed === '') {
            return $content;
        }

        $normalized = str($trimmed)->lower()->squish()->toString();
        $staleFailurePhrases = [
            'bean could not finish',
            'could not finish that request',
            'bean could not complete',
            'could not complete the requested change',
            'i could not complete',
            'i tried to check that live information',
            'i tried to check live information',
            'i could not get live information',
            'i couldn\'t get live information',
            'i couldn’t get live information',
            'could not get live information',
            'couldn\'t get live information',
            'couldn’t get live information',
            'lookup did not return',
            'lookup didn\'t return',
            'lookup didn’t return',
            'could not get that live lookup back quickly enough',
            'couldn\'t get that live lookup back quickly enough',
            'couldn’t get that live lookup back quickly enough',
            'live lookup back quickly enough',
            'did not return a usable result',
            'no usable result',
            'i’m still checking',
            'i\'m still checking',
            'still checking live sources',
            'still checking live weather',
            'response did not come through',
            'something unexpected happened',
        ];

        foreach ($staleFailurePhrases as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return 'I’m checking the latest app state now. If I need one more detail, I’ll ask.';
            }
        }

        return $content;
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'conversation_session_id');
    }
}
