<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

class Client extends Actor
{
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'date_of_birth',
        'region',
        'city',
        'address',
        'status',
        'profile_image',
        'last_login_at',
    ];

    protected $hidden = [
        "updated_at",
    ];

    protected $casts = [
        'last_login_at' => 'datetime',
    ];
}
