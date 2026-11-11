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
            // Some error payloads omit statusCode or return HTTP 200 with a failure message.
            $bodyStatus = array_key_exists('statusCode', $body)
                ? (int) $body['statusCode']
                : (int) $http->status();
            $ok = $http->successful()
                && $bodyStatus >= 200
                && $bodyStatus < 300
                && ! isset($body['error']);

            $httpStatus = (int) $http->status();
            // 5xx: the request may have been accepted before Kairos failed to reply.
            $uncertain = ! $ok && $httpStatus >= 500;

            $response = new KairosResponse(
                $ok,
                $httpStatus,
                $body,
                $ok ? null : (string) ($body['message'] ?? $body['statusMessage'] ?? 'HTTP '.$httpStatus),
                $this->elapsed($started),
                $uncertain,
            );
        } catch (ConnectionException $e) {
            // Read/operation timeouts are uncertain (message may already be queued at Kairos).
            // Connect/DNS failures are safe to treat as "never sent".
            $uncertain = $this->isTimeoutFailure($e->getMessage());
            $response = new KairosResponse(
                false,
                0,
                [],
                ($uncertain ? 'Gateway timeout: ' : 'Connection failed: ').$e->getMessage(),
                $this->elapsed($started),
                $uncertain,
            );
        } catch (Throwable $e) {
            Log::error('Kairos call failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            $response = new KairosResponse(false, 0, [], $e->getMessage(), $this->elapsed($started));
        }

        // Full bodies → file. Compact keys → DB (response JSON only when the call failed).
        $this->logToFile($endpoint, $path, $inboxId, $messageCount, $response);

        if ($log || ! $response->ok) {
            $this->logToDatabase($endpoint, $inboxId, $messageCount, $response);
        }

        return $response;
    }

    protected function logToFile(
        string $endpoint,
        string $path,
        ?int $inboxId,
        int $messageCount,
        KairosResponse $response,
    ): void {
        if (! config('sms.kairos.log_bodies', true)) {
            return;
        }

        try {
            Log::channel('kairos')->log(
                $response->ok ? 'info' : 'warning',
                'Kairos '.$endpoint,
                [
                    'endpoint' => $endpoint,
                    'path' => $path,
                    'inbox_id' => $inboxId,
                    'message_count' => $messageCount,
                    'ok' => $response->ok,
                    'http_status' => $response->httpStatus,
                    'duration_ms' => $response->durationMs,
                    'transaction_id' => $response->transactionId(),
                    'sequence_no' => $response->sequenceNo(),
                    'status_message' => $response->statusMessage(),
                    'error' => $response->error,
                    'response' => $response->body !== [] ? $response->body : null,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Could not write Kairos file log', ['error' => $e->getMessage()]);
        }
    }

    protected function logToDatabase(string $endpoint, ?int $inboxId, int $messageCount, KairosResponse $response): void
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
                // Keep the DB lean: full body only for failures.
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

    protected function isTimeoutFailure(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'operation timed out');
    }
}
