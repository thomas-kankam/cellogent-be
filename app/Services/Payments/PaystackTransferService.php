<?php

namespace App\Services\Payments;

use App\Support\GhanaPhone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Pays money rewards to a mobile money wallet with the Paystack Transfer API
 * (transfer recipient + transfer). Amounts are GHS; Paystack takes pesewas.
 *
 * Every call returns a plain array:
 *  - state: success | pending | failed (definite, do not retry) | unavailable (retry later)
 *  - message, transfer_code, reference, recipient_code, raw
 */
class PaystackTransferService
{
    public const SUCCESS = 'success';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const UNAVAILABLE = 'unavailable';

    public function configured(): bool
    {
        return filled(config('payments.gateways.paystack.secret_key'));
    }

    /**
     * Paystack mobile money bank code for a Ghana number: MTN, VOD (Telecel) or ATL (AirtelTigo).
     */
    public function bankCode(string $phone, ?string $network = null): string
    {
        $key = strtolower(preg_replace('/[^a-z]/i', '', (string) $network) ?? '');

        if (str_contains($key, 'mtn')) {
            return 'MTN';
        }

        if (str_contains($key, 'tele') || str_contains($key, 'vod')) {
            return 'VOD';
        }

        if (str_contains($key, 'air') || str_contains($key, 'tigo') || $key === 'at') {
            return 'ATL';
        }

        $local = GhanaPhone::toMobile($phone) ?? $phone;
        $local = $this->local($local);

        return match (substr($local, 0, 3)) {
            '024', '054', '055', '059', '025', '053' => 'MTN',
            '020', '050' => 'VOD',
            '026', '056', '027', '057' => 'ATL',
            default => 'MTN',
        };
    }

    /**
     * Create (or reuse, Paystack de-duplicates) a mobile money transfer recipient.
     *
     * @return array<string, mixed>
     */
    public function recipient(string $phone, string $name, ?string $network = null): array
    {
        if (! $this->configured()) {
            return $this->result(self::UNAVAILABLE, 'Paystack is not configured.');
        }

        try {
            $response = $this->http()->post('/transferrecipient', [
                'type' => 'mobile_money',
                'name' => $name !== '' ? $name : 'Reward recipient',
                'account_number' => $this->local($phone),
                'bank_code' => $this->bankCode($phone, $network),
                'currency' => config('payments.currency', 'GHS'),
            ]);
        } catch (ConnectionException $e) {
            return $this->result(self::UNAVAILABLE, 'Paystack unreachable: '.$e->getMessage());
        }

        $body = $response->json() ?? [];

        if ($response->serverError() || in_array($response->status(), [401, 403], true)) {
            return $this->result(self::UNAVAILABLE, 'Paystack HTTP '.$response->status(), $body);
        }

        $code = $body['data']['recipient_code'] ?? null;

        if (! ($body['status'] ?? false) || ! $code) {
            return $this->result(self::FAILED, (string) ($body['message'] ?? 'The recipient could not be created.'), $body);
        }

        return $this->result(self::SUCCESS, 'Recipient created.', $body, ['recipient_code' => (string) $code]);
    }

    /**
     * Start a transfer. A timeout or 5xx is `unavailable` and the reference must be verified
     * before trying again, because the transfer may still have been created.
     *
     * @return array<string, mixed>
     */
    public function transfer(string $recipientCode, float $amount, string $reference, string $reason): array
    {
        if (! $this->configured()) {
            return $this->result(self::UNAVAILABLE, 'Paystack is not configured.');
        }

        try {
            $response = $this->http()->post('/transfer', [
                'source' => 'balance',
                'amount' => (int) round($amount * 100),
                'currency' => config('payments.currency', 'GHS'),
                'recipient' => $recipientCode,
                'reference' => $this->reference($reference),
                'reason' => mb_substr($reason, 0, 100),
            ]);
        } catch (ConnectionException $e) {
            return $this->result(self::UNAVAILABLE, 'Paystack unreachable: '.$e->getMessage(), [], ['ambiguous' => true]);
        }

        $body = $response->json() ?? [];

        if ($response->serverError()) {
            return $this->result(self::UNAVAILABLE, 'Paystack HTTP '.$response->status(), $body, ['ambiguous' => true]);
        }

        if (in_array($response->status(), [401, 403], true) && ! ($body['status'] ?? false)) {
            return $this->result(self::UNAVAILABLE, (string) ($body['message'] ?? 'Paystack rejected the credentials.'), $body);
        }

        if (! ($body['status'] ?? false)) {
            return $this->result(self::FAILED, (string) ($body['message'] ?? 'The transfer was rejected.'), $body);
        }

        return $this->fromTransfer($body);
    }

    /**
     * Current state of a transfer by the reference we sent.
     *
     * @return array<string, mixed>
     */
    public function verify(string $reference): array
    {
        if (! $this->configured()) {
            return $this->result(self::UNAVAILABLE, 'Paystack is not configured.');
        }

        try {
            $response = $this->http()->get('/transfer/verify/'.rawurlencode($this->reference($reference)));
        } catch (ConnectionException $e) {
            return $this->result(self::UNAVAILABLE, 'Paystack unreachable: '.$e->getMessage());
        }

        $body = $response->json() ?? [];

        if ($response->status() === 404) {
            return $this->result(self::FAILED, 'Transfer not found.', $body, ['not_found' => true]);
        }

        if ($response->serverError() || ! ($body['status'] ?? false)) {
            return $this->result(self::UNAVAILABLE, (string) ($body['message'] ?? 'Paystack HTTP '.$response->status()), $body);
        }

        return $this->fromTransfer($body);
    }

    /**
     * Paystack references are lowercase letters, digits, - and _ (16-50 chars).
     */
    public function reference(string $reference): string
    {
        $clean = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $reference) ?? '');

        return str_pad($clean, 16, '0');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function fromTransfer(array $body): array
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $status = strtolower((string) ($data['status'] ?? 'pending'));
        $extra = [
            'transfer_code' => $data['transfer_code'] ?? null,
            'reference' => $data['reference'] ?? null,
        ];

        return match ($status) {
            'success' => $this->result(self::SUCCESS, 'Transfer completed.', $body, $extra),
            'failed', 'reversed', 'rejected', 'blocked', 'abandoned' => $this->result(self::FAILED, (string) ($data['reason'] ?? $body['message'] ?? 'The transfer failed.'), $body, $extra),
            // OTP-enabled accounts cannot finish a transfer through the API; surface it for the admin.
            'otp' => $this->result(self::FAILED, 'Paystack requires an OTP to approve transfers. Disable transfer OTP in the Paystack dashboard.', $body, $extra + ['otp' => true]),
            default => $this->result(self::PENDING, 'Transfer is being processed.', $body, $extra),
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function result(string $state, string $message, array $raw = [], array $extra = []): array
    {
        return $extra + ['state' => $state, 'message' => $message, 'raw' => $raw];
    }

    protected function local(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($digits, '233') ? '0'.substr($digits, 3) : $digits;
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl((string) config('payments.gateways.paystack.base_url'))
            ->withToken((string) config('payments.gateways.paystack.secret_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('payments.gateways.paystack.timeout', 30));
    }
}
