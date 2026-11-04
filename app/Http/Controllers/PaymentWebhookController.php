<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\PaymentVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Public gateway notifications. Each one only identifies the payment;
 * the real status is always re-checked with the gateway API before anything is credited.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(protected PaymentVerifier $verifier) {}

    public function paystack(Request $request, PaystackGateway $paystack): JsonResponse
    {
        if (! $paystack->validSignature($request->getContent(), $request->header('x-paystack-signature'))) {
            return response()->json(['ok' => false], 401);
        }

        $event = (string) $request->input('event');
        $reference = (string) $request->input('data.reference');

        if ($reference !== '' && str_starts_with($event, 'charge.')) {
            $this->check(Payment::query()->where('reference_id', $reference)->where('gateway', 'paystack')->first());
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Where Paystack sends the customer after checkout.
     */
    public function paystackReturn(Request $request): RedirectResponse
    {
        $reference = (string) ($request->query('reference') ?: $request->query('trxref'));
        $payment = $reference !== ''
            ? Payment::query()->where('reference_id', $reference)->where('gateway', 'paystack')->first()
            : null;

        $status = $payment ? $this->check($payment) : 'unknown';

        return redirect()->away($this->returnUrl($reference, $status));
    }

    public function kairos(Request $request): JsonResponse
    {
        $token = (string) config('payments.gateways.kairos.webhook_token');

        if ($token !== '' && ! hash_equals($token, (string) ($request->query('token') ?? $request->header('x-webhook-token')))) {
            return response()->json(['ok' => false], 401);
        }

        $payload = $request->all();
        $candidates = array_filter(array_map('strval', [
            Arr::get($payload, 'referenceNo'),
            Arr::get($payload, 'data.referenceNo'),
            Arr::get($payload, 'reference'),
            Arr::get($payload, 'data.reference'),
            Arr::get($payload, 'referenceId'),
            Arr::get($payload, 'data.referenceId'),
            Arr::get($payload, 'transactionId'),
            Arr::get($payload, 'data.transactionId'),
        ]));

        $payment = $candidates === [] ? null : Payment::query()
            ->where('gateway', 'kairos')
            ->where(fn ($q) => $q->whereIn('reference_id', $candidates)->orWhereIn('gateway_reference', $candidates))
            ->first();

        if (! $payment) {
            Log::info('Kairos payment webhook with no matching payment', ['payload' => $payload]);
        }

        $this->check($payment);

        return response()->json(['ok' => true]);
    }

    protected function check(?Payment $payment): string
    {
        if (! $payment) {
            return 'unknown';
        }

        try {
            return $this->verifier->verify($payment);
        } catch (\Throwable $e) {
            Log::error('Payment verification from webhook failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);

            return $payment->status;
        }
    }

    protected function returnUrl(string $reference, string $status): string
    {
        $base = (string) config('payments.return_url');

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query(['reference' => $reference, 'status' => $status]);
    }
}
