<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = [
        'name',
        'module',
    ];

    protected $hidden = [
        "updated_at",
    ];

    public function admins()
    {
        return $this->belongsToMany(Admin::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
}
