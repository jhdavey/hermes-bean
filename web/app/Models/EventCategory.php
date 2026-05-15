<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventCategory extends Model
{
    protected $fillable = ['user_id', 'workspace_id', 'created_by_user_id', 'name', 'color', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
