<?php

namespace App\Services\Sms;

use App\Models\AggregatorRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin wrapper over the Kairos Afrika SMS API. Admin/internal use only:
 * nothing from here is returned to clients as-is.
 */
class KairosClient
{
    public const NAME = 'kairos';

    public function quick(string $to, string $from, string $message, ?int $inboxId = null): KairosResponse
    {
        return $this->call('post', '/external/sms/quick', 'quick', [
            'to' => $to,
            'from' => $from,
            'type' => 'Quick',
            'message' => $message,
            'isGlobal' => false,
        ], $inboxId, 1);
    }

    /**
     * @param  list<array{to: string, from: string, message: string}>  $messages
     */
    public function bulk(array $messages, ?int $inboxId = null): KairosResponse
    {
        return $this->call('post', '/external/sms/bulk', 'bulk', [
            'messages' => array_map(fn (array $m) => $m + ['type' => 'Quick'], $messages),
            'isGlobal' => false,
        ], $inboxId, count($messages));
    }

    public function resend(string $messageId, string $message, ?int $inboxId = null): KairosResponse
    {
        return $this->call('post', '/external/sms/quick/'.rawurlencode($messageId).'/resend', 'resend', [
            'message' => $message,
        ], $inboxId, 1);
    }

    public function deliveryReceipt(string $sequenceNo): KairosResponse
    {
        return $this->call('get', '/external/sms/logs/'.rawurlencode($sequenceNo), 'dlr', [], null, 0, false);
    }

    public function balance(): KairosResponse
    {
        return $this->call('get', '/external/account/balance', 'balance');
    }

    public function baseUrl(): string
    {
        $env = config('sms.kairos.environment', 'production');

        return rtrim((string) config("sms.kairos.base_urls.{$env}"), '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function call(
        string $method,
        string $path,
        string $endpoint,
        array $payload = [],
        ?int $inboxId = null,
        int $messageCount = 0,
        bool $log = true,
    ): KairosResponse {
        $started = microtime(true);

        try {
            $request = Http::withHeaders([
                'x-api-key' => (string) config('sms.kairos.key'),
                'x-api-secret' => (string) config('sms.kairos.secret'),
                'x-api-version' => (string) config('sms.kairos.version'),
            ])
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('sms.kairos.timeout', 30))
                ->connectTimeout((int) config('sms.kairos.connect_timeout', 5));

            $http = $method === 'get'
                ? $request->get($this->baseUrl().$path)
                : $request->post($this->baseUrl().$path, $payload);

            $body = $http->json();
            $body = is_array($body) ? $body : ['raw' => mb_substr($http->body(), 0, 1000)];
            $bodyStatus = (int) ($body['statusCode'] ?? $http->status());
            $ok = $http->successful() && $bodyStatus >= 200 && $bodyStatus < 300;

            $response = new KairosResponse(
                $ok,
                $http->status(),
                $body,
                $ok ? null : (string) ($body['message'] ?? $body['statusMessage'] ?? 'HTTP '.$http->status()),
                $this->elapsed($started),
            );
        } catch (ConnectionException $e) {
            $response = new KairosResponse(false, 0, [], 'Connection failed: '.$e->getMessage(), $this->elapsed($started));
        } catch (Throwable $e) {
            Log::error('Kairos call failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            $response = new KairosResponse(false, 0, [], $e->getMessage(), $this->elapsed($started));
        }

        if ($log || ! $response->ok) {
            $this->log($endpoint, $inboxId, $messageCount, $response);
        }

        return $response;
    }

    protected function log(string $endpoint, ?int $inboxId, int $messageCount, KairosResponse $response): void
    {
        try {
            AggregatorRequest::create([
                'aggregator' => self::NAME,
                'endpoint' => $endpoint,
                'inbox_id' => $inboxId,
                'message_count' => $messageCount,
                'http_status' => $response->httpStatus ?: null,
                'ok' => $response->ok,
                'status_message' => mb_substr((string) $response->statusMessage(), 0, 255),
                'transaction_id' => $response->transactionId(),
                'sequence_no' => $response->sequenceNo(),
                'duration_ms' => $response->durationMs,
                'response' => $response->ok ? null : ($response->body ?: ['error' => $response->error]),
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not log aggregator request', ['error' => $e->getMessage()]);
        }
    }

    protected function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
