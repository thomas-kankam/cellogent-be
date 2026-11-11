<?php

namespace App\Services\Utilities;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * SwiftData Ghana Developer API v2 — https://swiftdatagh.shop/api-docs
 *
 * Auth: Authorization: Bearer swft_live_…  (or X-API-Key)
 * POSTs should send X-Idempotency-Key. Optional HMAC via X-Swift-Signature.
 */
class SwiftClient
{
    public function account(): SwiftResponse
    {
        return $this->call('get', '/account');
    }

    public function balance(): SwiftResponse
    {
        return $this->call('get', '/balance');
    }

    public function wallets(): SwiftResponse
    {
        return $this->call('get', '/wallets');
    }

    /**
     * @param  array{from: string, to: string, amount: float|int}  $payload
     */
    public function walletTransfer(array $payload): SwiftResponse
    {
        return $this->call('post', '/wallet/transfer', $payload);
    }

    public function plans(): SwiftResponse
    {
        return $this->call('get', '/plans');
    }

    public function serviceStatus(): SwiftResponse
    {
        return $this->call('get', '/service-status');
    }

    /**
     * @param  array{network: string, phone: string, amount: float|int, request_id?: string}  $payload
     */
    public function buyAirtime(array $payload): SwiftResponse
    {
        return $this->call('post', '/payment/airtime', $this->withRequestId($payload));
    }

    /**
     * @param  array{package_id: string, phone: string, request_id?: string}  $payload
     */
    public function buyData(array $payload): SwiftResponse
    {
        return $this->call('post', '/payment/data', $this->withRequestId($payload));
    }

    /**
     * @param  array{phone: string, network?: string}  $payload
     */
    public function verifyBeneficiary(array $payload): SwiftResponse
    {
        return $this->callAbsolute('post', (string) config('swift.verify_url'), $payload);
    }

    /**
     * Up to 30 numbers per request (comma or newline separated string, or list).
     *
     * @param  array{numbers: string|list<string>}  $payload
     */
    public function submitNumbers(array $payload): SwiftResponse
    {
        $numbers = $payload['numbers'] ?? '';

        if (is_array($numbers)) {
            $numbers = implode(', ', $numbers);
        }

        return $this->call('post', '/purchases/submit-numbers', ['numbers' => (string) $numbers]);
    }

    /**
     * @param  array{customerNumber: string, billType: string}  $payload
     */
    public function validateBill(array $payload): SwiftResponse
    {
        return $this->call('post', '/payment/bills/validate', $payload);
    }

    /**
     * @param  array{customerNumber: string, billType: string, amount: float|int, senderName?: string}  $payload
     */
    public function payBill(array $payload): SwiftResponse
    {
        return $this->call('post', '/payment/bills/pay', $this->withRequestId($payload));
    }

    /**
     * @param  array{accountNumber: string}  $payload
     */
    public function ecgLookup(array $payload): SwiftResponse
    {
        return $this->call('post', '/payment/ecg/lookup', $payload);
    }

    /**
     * @param  array{phoneNumber: string, accountNumber: string, amount: float|int}  $payload
     */
    public function payEcg(array $payload): SwiftResponse
    {
        return $this->call('post', '/payment/ecg', $this->withRequestId($payload));
    }

    /**
     * @param  array{checker_type: string, customer_phone: string, quantity: int, amount: float|int, request_id?: string}  $payload
     */
    public function resultsChecker(array $payload): SwiftResponse
    {
        return $this->call('post', '/results-checker', $this->withRequestId($payload));
    }

    /**
     * @param  array{limit?: int, offset?: int, status?: string, network?: string}  $query
     */
    public function orders(array $query = []): SwiftResponse
    {
        $qs = $query !== [] ? ('?'.http_build_query($query)) : '';

        return $this->call('get', '/orders'.$qs);
    }

    public function orderStatus(string $orderId): SwiftResponse
    {
        return $this->call('get', '/status?order_id='.rawurlencode($orderId));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withRequestId(array $payload): array
    {
        $payload['request_id'] = $payload['request_id'] ?? (string) Str::uuid();

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function call(string $method, string $path, array $payload = []): SwiftResponse
    {
        return $this->callAbsolute($method, rtrim((string) config('swift.base_url'), '/').$path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function callAbsolute(string $method, string $url, array $payload = []): SwiftResponse
    {
        $started = microtime(true);
        $key = (string) config('swift.api_key');

        if ($key === '') {
            return new SwiftResponse(false, 0, [], 'Swift API key is not configured.', 0);
        }

        try {
            $headers = [
                'Authorization' => 'Bearer '.$key,
                'X-API-Key' => $key,
                'Accept' => 'application/json',
            ];

            if ($method === 'post') {
                $headers['X-Idempotency-Key'] = (string) ($payload['request_id'] ?? Str::uuid());
                $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
                $secret = (string) config('swift.secret_key');

                if ($secret !== '' && is_string($raw)) {
                    $headers['X-Swift-Signature'] = hash_hmac('sha256', $raw, $secret);
                }
            }

            $request = Http::withHeaders($headers)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('swift.timeout', 30))
                ->connectTimeout((int) config('swift.connect_timeout', 5));

            $http = $method === 'get'
                ? $request->get($url)
                : $request->post($url, $payload);

            $body = $http->json();
            $body = is_array($body) ? $body : ['raw' => mb_substr($http->body(), 0, 1000)];
            $ok = $http->successful() && (($body['success'] ?? false) === true);

            return new SwiftResponse(
                $ok,
                (int) $http->status(),
                $body,
                $ok ? null : (string) ($body['error'] ?? $body['message'] ?? 'HTTP '.$http->status()),
                (int) round((microtime(true) - $started) * 1000),
            );
        } catch (ConnectionException $e) {
            return new SwiftResponse(false, 0, [], 'Swift connection failed: '.$e->getMessage(), (int) round((microtime(true) - $started) * 1000));
        } catch (Throwable $e) {
            Log::error('Swift call failed', ['url' => $url, 'error' => $e->getMessage()]);

            return new SwiftResponse(false, 0, [], $e->getMessage(), (int) round((microtime(true) - $started) * 1000));
        }
    }
}
