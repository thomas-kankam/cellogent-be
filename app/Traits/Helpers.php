<?php

namespace App\Traits;

use App\Models\Otp;

trait Helpers
{
    protected static function otpCode(string $type, int $actor_id, string $channel, string $guard): string
    {
        $token = self::normalizeOtp(random_int(111111, 999999));

        Otp::create([
            'token' => $token,
            'actor_id' => $actor_id,
            'guard' => $guard,
            'type' => $type,
            'channel' => $channel,
            'expires_at' => now()->addMinutes(10),
        ]);

        return $token;
    }

    protected static function apiToken(Actor $actor, string $oauth_name): string
    {
        return $actor->createToken($oauth_name)->accessToken;
    }

    protected static function normalizeOtp(int $otp): string
    {
        return str_pad($otp, 6, '0', STR_PAD_LEFT);
    }
}
