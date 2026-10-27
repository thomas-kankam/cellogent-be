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
     * @return array{token: array<string, mixed>, account: array<string, mixed>}
     */
    public function login(Actor $actor): array
    {
        $actor->forceFill(['last_login_at' => now()])->save();

        return [
            'token' => $this->issueApiToken($actor, $actor->guardName().'-access'),
            'account' => $this->profiles->present($actor->fresh()),
        ];
    }
}
