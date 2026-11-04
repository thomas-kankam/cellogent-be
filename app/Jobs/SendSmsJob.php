<?php

namespace App\Jobs;

use App\Models\Sent;
use App\Services\Sms\KairosClient;
use App\Support\GhanaPhone;
use App\Support\SmsThrottle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Platform messages (OTP, alerts) sent with SMS_SYSTEM_SENDER. No client units are used.
 */
class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public string $phoneNumber,
        public string $message,
    ) {
        $this->onQueue('sms-blast-quick');
    }

    public function handle(KairosClient $kairos): void
    {
        $sender = config('sms.system_sender');
        $to = GhanaPhone::toMobile($this->phoneNumber);

        if (! $sender || ! $to) {
            Log::info('System SMS skipped', ['phone' => $this->phoneNumber, 'reason' => $sender ? 'invalid number' : 'SMS_SYSTEM_SENDER not set']);

            return;
        }

        SmsThrottle::await('quick', 1);

        $response = $kairos->quick($to, $sender, $this->message);
        $ids = $response->messagesByRecipient()[$to] ?? [];

        Sent::create([
            'request_type' => 'system',
            'type' => 'quick',
            'to' => $to,
            'from' => $sender,
            'message' => $this->message,
            'units_used' => 1,
            'aggregator' => KairosClient::NAME,
            'message_id' => $ids['message_id'] ?? $response->transactionId(),
            'sequence_no' => $ids['sequence_no'] ?? $response->sequenceNo(),
            'job_id' => $ids['job_id'] ?? null,
            'status' => $response->ok ? Sent::SUBMITTED : Sent::FAILED,
            'error' => $response->ok ? null : mb_substr((string) $response->error, 0, 255),
            'submitted_at' => $response->ok ? now() : null,
            'next_dlr_at' => $response->ok ? now()->addSeconds((int) config('sms.dlr.first_check_after', 60)) : null,
        ]);
    }
}
