<?php

namespace App\Repositories;

use App\Models\Client;

class ClientRepository
{
    public function create(array $attributes): Client
    {
        return Client::create($attributes);
    }
}
