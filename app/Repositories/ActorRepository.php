<?php

namespace App\Repositories;

use App\Models\Actor;
use App\Models\Admin;
use App\Models\Client;

class ActorRepository
{
    /**
     * Resolve the Eloquent model for a Passport guard.
     *
     * @return class-string<Actor>
     */
    public function modelForGuard(string $guard): string
    {
        return $guard === 'admin' ? Admin::class : Client::class;
    }

    public function findByEmail(string $guard, string $email): ?Actor
    {
        $model = $this->modelForGuard($guard);

        return $model::query()->where('email', $email)->first();
    }

    public function emailTaken(string $guard, string $email): bool
    {
        return $this->findByEmail($guard, $email) !== null;
    }
}
