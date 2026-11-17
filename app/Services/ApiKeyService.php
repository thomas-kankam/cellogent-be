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
     * Only a masked hint is ever shown again; the full token is returned once, at create/regenerate.
     *
     * @return array<string, mixed>
     */
    public function present(ApiKey $apiKey, ?string $plainToken = null): array
    {
        return [
            'id' => $apiKey->id,
            'resource' => 'api_key',
            'client_id' => $apiKey->client_id,
            'token' => $apiKey->token,
            'token_hint' => $apiKey->token_prefix ? $apiKey->token_prefix.'••••••••' : null,
            'status' => $apiKey->status,
            'last_used_at' => optional($apiKey->last_used_at)->toDateTimeString(),
            'created_at' => optional($apiKey->created_at)->toDateTimeString(),
        ];
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

        $plain = $this->makeToken();
        $apiKey = $this->upsertSingleKey($client, $plain);

        $this->activity()->record('API key created', 'api_key', 'created', 'client', $client, $client, [
            'api_key_id' => $apiKey->id,
        ]);

        return $this->result(
            false,
            'API key created.',
            ApiCode::CREATED,
            ApiReason::CREATED,
            $this->present($apiKey, $plain),
        );
    }

    /**
     * Replace the client's existing API key record in-place with a new token.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function regenerate(Client $client): array
    {
        $plain = $this->makeToken();
        $apiKey = $this->upsertSingleKey($client, $plain);

        $this->activity()->record('API key regenerated', 'api_key', 'updated', 'client', $client, $client, [
            'api_key_id' => $apiKey->id,
        ]);

        return $this->result(
            false,
            'API key regenerated. The previous key has been replaced.',
            ApiCode::SUCCESS,
            ApiReason::SUCCESS,
            $this->present($apiKey, $plain),
        );
    }

    protected function upsertSingleKey(Client $client, string $plain): ApiKey
    {
        return DB::transaction(function () use ($client, $plain) {
            $apiKey = ApiKey::updateOrCreate(
                ['client_id' => $client->id],
                $this->keyAttributes($client, $plain),
            );

            ApiKey::query()
                ->where('client_id', $client->id)
                ->where('id', '!=', $apiKey->id)
                ->delete();

            return $apiKey;
        });
    }

    protected function makeToken(): string
    {
        return 'cgk_'.Str::random(48);
    }

    /**
     * @return array<string, mixed>
     */
    protected function keyAttributes(Client $client, string $plain): array
    {
        return [
            'client_id' => $client->id,
            'token' => $plain,
            'token_prefix' => substr($plain, 0, 12),
            'status' => 'active',
            'last_used_at' => null,
        ];
    }
}
