<?php

namespace App\Jobs;

use App\Mail\WelcomeMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendWelcomeMailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $email,
        public string $purpose,
        public ?string $displayName = null,
    ) {
        $this->onQueue('email-triggers');
    }

    public function handle(): void
    {
        Mail::to($this->email)->send(new WelcomeMail(
            $this->email,
            $this->purpose,
            $this->displayName,
        ));
    }
}
