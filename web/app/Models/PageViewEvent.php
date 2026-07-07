<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageViewEvent extends Model
{
    protected $fillable = [
        'user_id',
        'visitor_key',
        'ip_hash',
        'method',
        'path',
        'route_name',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'user_agent',
        'status_code',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
