<?php

namespace App\Jobs;

use App\Mail\ContactUploadCompleteMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendContactUploadMailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $stats
     */
    public function __construct(
        public string $email,
        public array $stats,
        public ?string $displayName = null,
    ) {
        $this->onQueue('email-triggers');
    }

    public function handle(): void
    {
        Mail::to($this->email)->send(new ContactUploadCompleteMail(
            $this->email,
            $this->stats,
            $this->displayName,
        ));
    }
}
