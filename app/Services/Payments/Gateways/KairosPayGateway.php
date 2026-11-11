<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Kairos Afrika Payment API: sends a mobile money prompt to the payer's phone.
 */
class KairosPayGateway implements PaymentGatewayDriver
{
    public function code(): string
    {
        return 'kairos';
    }

    public function configured(): bool
    {
        return filled($this->config('username')) && filled($this->config('password')) && filled($this->config('callback_id'));
    }

    public function initiate(Payment $payment): GatewayResult
    {
        try {
            $response = $this->http()->post('/v1/payment', [
                'amount' => (float) $payment->total_amount,
                'referenceNo' => $payment->reference_id,
                'payee' => (string) $this->config('payee'),
                'narration' => mb_substr(str_replace('_', ' ', ucfirst((string) $payment->purpose)).' '.$payment->reference_id, 0, 60),
                'msisdn' => (string) $payment->msisdn,
                'callbackId' => (string) $this->config('callback_id'),
                'payer' => mb_substr((string) ($payment->email ?: $payment->msisdn), 0, 60),
            ]);
        } catch (ConnectionException $e) {
            // Connected but no answer: the prompt may already be on the phone, so do not
            // fall back to another gateway (double charge). Status checks settle it.
            if (str_contains($e->getMessage(), 'Operation timed out')) {
                return new GatewayResult(true, reference: $payment->reference_id, message: 'Approve the mobile money prompt on your phone.', raw: ['timeout' => $e->getMessage()]);
            }

            return GatewayResult::unavailable('Kairos unreachable: '.$e->getMessage());
        }

        $body = $response->json() ?? [];

        // Bad credentials: no prompt was sent, safe to try the next gateway.
        if (in_array($response->status(), [401, 403], true)) {
            return GatewayResult::unavailable('Kairos HTTP '.$response->status(), $body);
        }

        // 5xx after the request reached Kairos: the prompt may already be on the phone.
        // Do not fall back (double charge / lost payment); status checks settle it.
        if ($response->serverError()) {
            return new GatewayResult(true, reference: $payment->reference_id, message: 'Approve the mobile money prompt on your phone.', raw: $body + ['http_status' => $response->status()]);
        }

        if (($body['statusCode'] ?? null) !== '0001') {
            return GatewayResult::rejected((string) ($body['statusMessage'] ?? 'Payment could not be started.'), $body);
        }

        $data = $body['data'] ?? [];

        return new GatewayResult(
            ok: true,
            reference: (string) ($data['referenceId'] ?? $body['transactionId'] ?? $payment->reference_id),
            gatewayCharge: isset($data['charge']) ? (float) $data['charge'] : null,
            message: 'Approve the mobile money prompt on your phone.',
            raw: $body,
        );
    }

    public function status(Payment $payment): GatewayStatus
    {
        $reference = $payment->gateway_reference ?: $payment->reference_id;

        try {
            $response = $this->http()->get('/v1/payment/'.rawurlencode((string) $reference).'/status');
        } catch (ConnectionException $e) {
            return new GatewayStatus(GatewayStatus::PENDING, message: $e->getMessage());
        }

        $body = $response->json() ?? [];
        $data = $body['data'] ?? [];

        if (! $response->successful() || ($body['statusCode'] ?? null) !== '0000') {
            return new GatewayStatus(GatewayStatus::PENDING, message: (string) ($body['statusMessage'] ?? 'HTTP '.$response->status()), raw: $body);
        }

        $state = match (strtoupper((string) ($data['status'] ?? ''))) {
            'COMPLETED' => GatewayStatus::PAID,
            'FAILED', 'CANCELLED' => GatewayStatus::FAILED,
            default => GatewayStatus::PENDING,
        };

        return new GatewayStatus(
            $state,
            amountPaid: isset($data['amount']) ? (float) $data['amount'] : null,
            gatewayCharge: isset($data['charge']) ? (float) $data['charge'] : null,
            message: $state === GatewayStatus::FAILED ? 'Mobile money payment '.strtolower((string) $data['status']).'.' : null,
            raw: $body,
            method: 'momo',
        );
    }

    /**
     * Kairos account holder lookup (name + network for a mobile money number).
     *
     * @return array<string, mixed>|null
     */
    public function accountHolder(string $msisdn): ?array
    {
        try {
            $response = $this->http()->get('/v1/accountholders/'.rawurlencode($msisdn));
        } catch (ConnectionException) {
            return null;
        }

        $body = $response->json() ?? [];

        return ($body['statusCode'] ?? null) === '0000' ? ($body['data'] ?? null) : null;
    }

    protected function http(): PendingRequest
    {
        $env = $this->config('environment', 'production');

        return Http::baseUrl((string) $this->config("base_urls.{$env}"))
            ->withBasicAuth((string) $this->config('username'), (string) $this->config('password'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout((int) $this->config('timeout', 30));
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("payments.gateways.kairos.{$key}", $default);
    }
}
