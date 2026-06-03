<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseCustomerLimit extends Model
{
    protected $fillable = [
        'user_id',
        'billing_type',
        'monthly_rate_usd',
        'usage_rate_usd',
        'limits',
        'notes',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'monthly_rate_usd' => 'float',
            'usage_rate_usd' => 'float',
            'limits' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
