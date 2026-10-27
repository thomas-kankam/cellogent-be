<?php

namespace App\Repositories;

use App\Models\Client;

class ClientRepository
{
    public function create(array $attributes): Client
    {
        return Client::create($attributes);
    }

    public function findByReferralCode(string $code): ?Client
    {
        return Client::query()->where('referral_code', $code)->first();
    }
}
