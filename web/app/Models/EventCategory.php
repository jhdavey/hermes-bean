<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventCategory extends Model
{
    protected $fillable = ['user_id', 'name', 'color', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
