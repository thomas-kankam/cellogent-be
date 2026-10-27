<?php

namespace App\Repositories;

use App\Models\Otp;

class OtpRepository
{
    /**
     * Expire any unused codes so only one open OTP exists per person + type.
     */
    public function invalidateOpen(string $actor, int $actorId, string $type): void
    {
        Otp::query()
            ->where('actor', $actor)
            ->where('actor_id', $actorId)
            ->where('type', $type)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    public function create(array $attributes): Otp
    {
        return Otp::create($attributes);
    }

    /**
     * Newest unused OTP for this email + guard + type.
     */
    public function latestOpen(string $actor, string $email, string $type): ?Otp
    {
        return Otp::query()
            ->where('actor', $actor)
            ->where('email', $email)
            ->where('type', $type)
            ->whereNull('used_at')
            ->latest('id')
            ->first();
    }
}
