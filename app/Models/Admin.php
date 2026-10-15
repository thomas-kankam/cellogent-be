<?php

namespace App\Models;

class Admin extends Actor
{
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'status',
        'role_id',
        'profile_image',
    ];

    protected $hidden = [
        "updated_at",
        "deleted_at",
    ];

    protected $casts = [
        'last_login_at' => 'datetime',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }
}
