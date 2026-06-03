<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EarlyAccessSignup extends Model
{
    protected $fillable = [
        'name',
        'email',
        'use_case',
        'requested_plan',
        'source',
    ];
}
