<?php

namespace App\Traits;

use App\Jobs\SendOtpMailJob;
use App\Jobs\SendSmsJob;
use App\Jobs\SendWelcomeMailJob;

/**
 * Fire-and-forget notifications. Email/SMS always go through queued jobs
 * so the HTTP request does not wait on Gmail, an SMS gateway, or Horizon.
 */
trait AppNotifications
{
    protected function notifyOtp(string $email, string $otp, string $purpose, ?string $displayName = null): void
    {
        SendOtpMailJob::dispatch($email, $otp, $purpose, $displayName);
    }

    /**
     * @param  array<string, mixed>  $meta  Optional: login_url, account_type, business_name
     */
    protected function notifyWelcome(string $email, string $purpose, ?string $displayName = null, array $meta = []): void
    {
        SendWelcomeMailJob::dispatch($email, $purpose, $displayName, $meta);
    }

    /**
     * SMS provider is not wired yet. The job logs the payload so Horizon
     * still has something to process when you add a gateway later.
     */
    protected function notifySms(string $phoneNumber, string $message): void
    {
        SendSmsJob::dispatch($phoneNumber, $message);
    }
}
