<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerJobRecord extends Model
{
    protected $fillable = ['user_id', 'name', 'status', 'scheduled_for', 'started_at', 'finished_at', 'payload', 'last_error'];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
