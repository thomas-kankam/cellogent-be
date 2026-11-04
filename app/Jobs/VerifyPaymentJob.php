<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Payments\PaymentVerifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VerifyPaymentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 15;

    public function __construct(public int $paymentId)
    {
        $this->onQueue('payments');
    }

    public function uniqueId(): string
    {
        return (string) $this->paymentId;
    }

    public function handle(PaymentVerifier $verifier): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if ($payment) {
            $verifier->verify($payment);
        }
    }
}
