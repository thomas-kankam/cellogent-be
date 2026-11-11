<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Payments\Gateways\GatewayStatus;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Log;

/**
 * Asks the gateway that took the payment for its real status, then completes or fails it.
 * Webhooks and callbacks only trigger this; their payloads are never trusted directly.
 */
class PaymentVerifier
{
    public function __construct(
        protected GatewayManager $gateways,
        protected PaymentService $payments,
    ) {}

    /**
     * @return string the payment status after the check
     */
    public function verify(Payment $payment): string
    {
        if ($payment->status === Payment::PAID || ! $payment->gateway || $payment->gateway === 'wallet') {
            return $payment->status;
        }

        $status = $this->gateways->driver($payment->gateway)->status($payment);

        if ($status->state === GatewayStatus::PAID) {
            $expected = (float) $payment->total_amount;

            if ($status->amountPaid !== null && $status->amountPaid + 0.009 < $expected) {
                Log::warning('Payment amount mismatch', ['payment_id' => $payment->id, 'paid' => $status->amountPaid, 'expected' => $expected]);

                if ($payment->status === Payment::PENDING) {
                    $this->payments->fail($payment, null, "Amount paid ({$status->amountPaid}) is less than expected ({$expected}). Contact support.", $status->raw);
                }

                return Payment::FAILED;
            }

            $this->payments->complete($payment, null, [
                'gateway_charge' => $status->gatewayCharge,
                'raw' => $status->raw,
                'method' => $status->method,
            ]);

            return Payment::PAID;
        }

        if ($payment->status !== Payment::PENDING) {
            return $payment->status;
        }

        if ($status->state === GatewayStatus::FAILED) {
            $this->payments->fail($payment, null, $status->message ?? 'Payment failed.', $status->raw);

            return Payment::FAILED;
        }

        $this->scheduleNext($payment);

        return Payment::PENDING;
    }

    protected function scheduleNext(Payment $payment): void
    {
        $backoff = (array) config('payments.verify_backoff', [60]);
        $attempt = (int) $payment->verify_attempts + 1;

        if ($attempt >= count($backoff)) {
            $this->payments->fail($payment, null, 'Payment was not completed in time.');

            return;
        }

        $payment->update([
            'verify_attempts' => $attempt,
            'next_verify_at' => now()->addSeconds((int) $backoff[$attempt]),
        ]);
    }
}
