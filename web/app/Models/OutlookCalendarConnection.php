<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutlookCalendarConnection extends Model
{
    protected $fillable = [
        'user_id',
        'outlook_account_email',
        'calendar_id',
        'status',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'token_expires_at',
        'last_synced_at',
        'last_error_at',
        'last_error',
        'oauth_state',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_error_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
