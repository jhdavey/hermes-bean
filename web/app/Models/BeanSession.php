<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BeanSession extends Model
{
    protected $fillable = ['user_id', 'workspace_id', 'title', 'status', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(BeanMessage::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BeanRun::class);
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(BeanActivityEvent::class);
    }

    public function voiceEvents(): HasMany
    {
        return $this->hasMany(BeanVoiceEvent::class);
    }
}
