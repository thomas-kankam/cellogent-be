<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Placeholder SMS job. Swap the body for your gateway (Hubtel, etc.) later.
 * Queuing it now means callers already use the same notifySms() API.
 */
class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $phoneNumber,
        public string $message,
    ) {
        $this->onQueue('sms-blast-quick');
    }

    public function handle(): void
    {
        Log::info('SMS queued (provider not configured)', [
            'phone' => $this->phoneNumber,
            'message' => $this->message,
        ]);
    }
}
