<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Paystack hosted checkout (card or mobile money). The client is redirected to authorization_url.
 */
class PaystackGateway implements PaymentGatewayDriver
{
    public function code(): string
    {
        return 'paystack';
    }

    public function configured(): bool
    {
        return filled(config('payments.gateways.paystack.secret_key'));
    }

    public function initiate(Payment $payment): GatewayResult
    {
        $payload = [
            'email' => $payment->email,
            'amount' => (int) round((float) $payment->total_amount * 100),
            'currency' => $payment->currency ?: 'GHS',
            'reference' => $payment->reference_id,
            // Null method → let the payer choose card or mobile money on Paystack.
            'channels' => match ($payment->method) {
                'card' => ['card'],
                'momo' => ['mobile_money'],
                default => ['card', 'mobile_money'],
            },
            'metadata' => [
                'payment_id' => $payment->id,
                'purpose' => $payment->purpose,
            ],
        ];

        if ($callback = config('payments.gateways.paystack.callback_url')) {
            $payload['callback_url'] = $callback;
        }

        try {
            $response = $this->http()->post('/transaction/initialize', $payload);
        } catch (ConnectionException $e) {
            return GatewayResult::unavailable('Paystack unreachable: '.$e->getMessage());
        }

        $body = $response->json() ?? [];

        if ($response->serverError() || in_array($response->status(), [401, 403], true)) {
            return GatewayResult::unavailable('Paystack HTTP '.$response->status(), $body);
        }

        if (! ($body['status'] ?? false) || empty($body['data']['authorization_url'])) {
            return GatewayResult::rejected((string) ($body['message'] ?? 'Payment could not be started.'), $body);
        }

        return new GatewayResult(
            ok: true,
            reference: (string) ($body['data']['reference'] ?? $payment->reference_id),
            redirectUrl: (string) $body['data']['authorization_url'],
            message: 'Complete the payment on the checkout page.',
            raw: $body,
        );
    }

    public function status(Payment $payment): GatewayStatus
    {
        $reference = $payment->gateway_reference ?: $payment->reference_id;

        try {
            $response = $this->http()->get('/transaction/verify/'.rawurlencode((string) $reference));
        } catch (ConnectionException $e) {
            return new GatewayStatus(GatewayStatus::PENDING, message: $e->getMessage());
        }

        $body = $response->json() ?? [];
        $data = $body['data'] ?? [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            return new GatewayStatus(GatewayStatus::PENDING, message: (string) ($body['message'] ?? 'HTTP '.$response->status()), raw: $body);
        }

        $state = match ((string) ($data['status'] ?? '')) {
            'success' => GatewayStatus::PAID,
            'failed', 'reversed' => GatewayStatus::FAILED,
            default => GatewayStatus::PENDING, // ongoing, pending, abandoned (client may still pay)
        };

        return new GatewayStatus(
            $state,
            amountPaid: isset($data['amount']) ? ((int) $data['amount']) / 100 : null,
            gatewayCharge: isset($data['fees']) ? ((int) $data['fees']) / 100 : null,
            message: $state === GatewayStatus::FAILED ? (string) ($data['gateway_response'] ?? 'Payment failed.') : null,
            raw: $body,
            method: $this->methodFromChannel($data['channel'] ?? null),
        );
    }

    public function validSignature(string $payload, ?string $signature): bool
    {
        $secret = (string) config('payments.gateways.paystack.secret_key');

        return $secret !== '' && $signature && hash_equals(hash_hmac('sha512', $payload, $secret), $signature);
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl((string) config('payments.gateways.paystack.base_url'))
            ->withToken((string) config('payments.gateways.paystack.secret_key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout((int) config('payments.gateways.paystack.timeout', 30));
    }

    protected function methodFromChannel(mixed $channel): ?string
    {
        return match ((string) $channel) {
            'card' => 'card',
            'mobile_money' => 'momo',
            default => null,
        };
    }
}
