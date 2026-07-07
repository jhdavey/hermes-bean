<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CouponCode extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'months_free_base',
        'created_by_user_id',
        'redeemed_by_user_id',
        'redeemed_at',
        'base_access_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'months_free_base' => 'integer',
            'redeemed_at' => 'datetime',
            'base_access_expires_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }
}
