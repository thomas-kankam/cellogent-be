<?php

namespace App\Repositories;

use App\Models\Admin;

class AdminRepository
{
    public function create(array $attributes): Admin
    {
        return Admin::create($attributes);
    }
}
