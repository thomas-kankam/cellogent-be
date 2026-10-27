<?php

namespace App\Models;

use App\Concerns\HasProfileImage;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * Shared authenticatable parent for Client and Admin.
 * Passport tokens are issued on this class via HasApiTokens.
 */
abstract class Actor extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasProfileImage, Notifiable;

    /**
     * Guard name used in OTP rows and activity logs: "client" or "admin".
     */
    abstract public function guardName(): string;
}
