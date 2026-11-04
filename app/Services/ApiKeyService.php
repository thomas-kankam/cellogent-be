<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\ApiKey;
use App\Models\Client;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiKeyService
{
    use LogsActivity;
    use ReturnsServiceResult;

    /**
     * @return array<string, mixed>
     */
    public function present(ApiKey $apiKey, ?string $plainToken = null): array
    {
        $payload = [
            'id' => $apiKey->id,
            'resource' => 'api_key',
            'client_id' => $apiKey->client_id,
            'status' => $apiKey->status,
            'last_used_at' => optional($apiKey->last_used_at)->toDateTimeString(),
            'created_at' => optional($apiKey->created_at)->toDateTimeString(),
        ];

        if ($plainToken) {
            // Plaintext token is only present on create / regenerate.
            $payload['token'] = $plainToken;
        }

        return $payload;
    }

    public function activeFor(Client $client): ?ApiKey
    {
        return ApiKey::query()
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function create(Client $client): array
    {
        if ($this->activeFor($client)) {
            return $this->result(
                true,
                'You already have an active API key. Regenerate it to get a new token.',
                ApiCode::BAD_REQUEST,
                ApiReason::INVALID,
            );
        }

        [$plain, $hash] = $this->makeToken();

        $apiKey = ApiKey::create([
            'client_id' => $client->id,
            'token' => $hash,
            'status' => 'active',
        ]);

        $this->activity()->record('API key created', 'api_key', 'created', 'client', $client, $client, [
            'api_key_id' => $apiKey->id,
        ]);

        return $this->result(
            false,
            'API key created. Copy the token now; it is not shown again.',
            ApiCode::CREATED,
            ApiReason::CREATED,
            $this->present($apiKey, $plain),
        );
    }

    /**
     * Deactivate every active key for the client, then create a new active key.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function regenerate(Client $client): array
    {
        $current = $this->activeFor($client);

        if (! $current) {
            return $this->result(true, 'No active API key to regenerate. Create one first.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        [$plain, $hash] = $this->makeToken();

        $apiKey = DB::transaction(function () use ($client, $hash) {
            ApiKey::query()
                ->where('client_id', $client->id)
                ->where('status', 'active')
                ->update(['status' => 'inactive']);

            return ApiKey::create([
                'client_id' => $client->id,
                'token' => $hash,
                'status' => 'active',
            ]);
        });

        $this->activity()->record('API key regenerated', 'api_key', 'updated', 'client', $client, $client, [
            'api_key_id' => $apiKey->id,
            'replaced_api_key_id' => $current->id,
        ]);

        return $this->result(
            false,
            'API key regenerated. The previous key is inactive. Copy the token now; it is not shown again.',
            ApiCode::SUCCESS,
            ApiReason::SUCCESS,
            $this->present($apiKey, $plain),
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function makeToken(): array
    {
        $plain = 'cgk_'.Str::random(48);

        return [$plain, hash('sha256', $plain)];
    }
}
