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

        if ($status->state === GatewayStatus::CANCELLED) {
            $this->payments->cancel($payment, null, $status->message ?? 'Payment was cancelled.', $status->raw);

            return Payment::CANCELLED;
        }

        $this->scheduleNext($payment, $status);

        return $payment->fresh()->status;
    }

    /**
     * Check any pending gateway payments for a client so viewing payments/orders reflects the live gateway state.
     */
    public function syncPendingForClient(int $clientId, ?int $onlyPaymentId = null): void
    {
        $query = Payment::query()
            ->where('client_id', $clientId)
            ->where('status', Payment::PENDING)
            ->whereNotNull('gateway')
            ->where('gateway', '!=', 'wallet');

        if ($onlyPaymentId !== null) {
            $query->whereKey($onlyPaymentId);
        } else {
            $query->latest('id')->limit(5);
        }

        foreach ($query->get() as $payment) {
            try {
                $this->verify($payment);
            } catch (\Throwable $e) {
                Log::warning('Live payment sync failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            }
        }
    }

    protected function scheduleNext(Payment $payment, GatewayStatus $status): void
    {
        $backoff = (array) config('payments.verify_backoff', [15, 25, 35, 60, 90, 120]);
        $attempt = (int) $payment->verify_attempts + 1;

        if ($attempt >= count($backoff)) {
            $reason = $status->message ?: 'Payment was not completed or was cancelled by user.';
            $this->payments->cancel($payment, null, $reason, $status->raw ?: null);

            return;
        }

        $changes = [
            'verify_attempts' => $attempt,
            'next_verify_at' => now()->addSeconds((int) $backoff[$attempt]),
        ];

        if ($status->raw !== []) {
            $changes['gateway_response'] = $status->raw;
        }

        $payment->update($changes);
    }
}
