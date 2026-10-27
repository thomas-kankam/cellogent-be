<?php

namespace App\Jobs;

use App\Mail\OtpMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the OTP email on the Redis/Horizon queue so the API returns immediately.
 */
class SendOtpMailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $email,
        public string $otp,
        public string $purpose,
        public ?string $displayName = null,
    ) {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        Mail::to($this->email)->send(new OtpMail(
            $this->email,
            $this->otp,
            $this->purpose,
            $this->displayName,
        ));
    }
}
