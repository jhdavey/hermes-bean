<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'token', 'token_hash', 'platform', 'device_id', 'app_version', 'enabled', 'last_seen_at'])]
class PushNotificationDeviceToken extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
