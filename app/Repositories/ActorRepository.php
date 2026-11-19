<?php

namespace App\Repositories;

use App\Models\Actor;
use App\Models\Admin;
use App\Models\Client;
use App\Support\GhanaPhone;

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

        return $model::query()->where('email', strtolower(trim($email)))->first();
    }

    public function emailTaken(string $guard, string $email): bool
    {
        return $this->findByEmail($guard, $email) !== null;
    }

    public function findByPhone(string $guard, ?string $phone): ?Actor
    {
        $variants = $this->phoneVariants($phone);

        if ($variants === []) {
            return null;
        }

        $model = $this->modelForGuard($guard);

        return $model::query()->whereIn('phone_number', $variants)->first();
    }

    public function phoneTaken(string $guard, ?string $phone): bool
    {
        return $this->findByPhone($guard, $phone) !== null;
    }

    /**
     * @return list<string>
     */
    public function phoneVariants(?string $phone): array
    {
        if ($phone === null || trim($phone) === '') {
            return [];
        }

        $raw = trim($phone);
        $normalized = GhanaPhone::normalize($raw);
        $variants = [$raw];

        if ($normalized) {
            $variants[] = $normalized;
            $variants[] = '+'.$normalized;

            if (str_starts_with($normalized, '233') && strlen($normalized) === 12) {
                $variants[] = '0'.substr($normalized, 3);
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }
}
