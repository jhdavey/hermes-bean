<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
