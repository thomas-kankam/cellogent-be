<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    protected $fillable = [
        'token',
        'actor',
        'actor_id',
        'type',
        'channel',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
