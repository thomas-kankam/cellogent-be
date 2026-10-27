<?php

namespace App\Services;

use App\Models\Actor;
use App\Traits\Helpers;

/**
 * After a successful OTP verify: stamp last_login_at and return Passport + profile.
 */
class AuthTokenService
{
    use Helpers;

    public function __construct(protected ProfilePresenter $profiles)
    {
    }

    /**
     * Flat login payload: token fields sit next to the profile (no wrappers).
     *
     * @return array<string, mixed>
     */
    public function login(Actor $actor): array
    {
        $actor->forceFill(['last_login_at' => now()])->save();

        return array_merge(
            $this->issueApiToken($actor, $actor->guardName().'-access'),
            $this->profiles->present($actor->fresh()),
        );
    }
}
