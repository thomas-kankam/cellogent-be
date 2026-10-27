<?php

namespace App\Support;

/**
 * Stable "reason" strings returned in the JSON body.
 * Frontends should branch on `reason`, not on free-text `message`.
 */
final class ApiReason
{
    public const SUCCESS = 'success';

    public const CREATED = 'created';

    public const VALIDATION_ERROR = 'validation_error';

    public const UNAUTHENTICATED = 'unauthenticated';

    public const FORBIDDEN = 'forbidden';

    public const SERVER_ERROR = 'server_error';

    public const TOO_MANY_REQUESTS = 'too_many_requests';

    public const OTP_SENT = 'otp_sent';

    public const VERIFIED = 'verified';

    public const EXPIRED = 'expired';

    public const NOT_FOUND = 'not_found';

    public const INVALID = 'invalid';

    public const ACCOUNT_INACTIVE = 'account_inactive';

    public const EMAIL_TAKEN = 'email_taken';

    public const ALREADY_PROCESSED = 'already_processed';

    public const INSUFFICIENT_BALANCE = 'insufficient_balance';
}
