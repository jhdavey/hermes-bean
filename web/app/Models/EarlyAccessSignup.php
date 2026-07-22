<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EarlyAccessSignup extends Model
{
    protected $fillable = [
        'name',
        'user_id',
        'email',
        'use_case',
        'requested_plan',
        'source',
        'status',
        'admitted_at',
        'waitlisted_at',
        'registered_at',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'admitted_at' => 'datetime',
            'waitlisted_at' => 'datetime',
            'registered_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
