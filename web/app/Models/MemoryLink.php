<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryLink extends Model
{
    protected $fillable = [
        'memory_item_id',
        'linkable_type',
        'linkable_id',
        'relationship',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function memoryItem(): BelongsTo
    {
        return $this->belongsTo(MemoryItem::class);
    }
}
