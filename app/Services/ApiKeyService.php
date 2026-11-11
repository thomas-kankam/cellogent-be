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
            'token' => $plainToken,
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
        $apiKey = ApiKey::create($this->keyAttributes($client, $plain));

        $this->activity()->record('API key created', 'api_key', 'created', 'client', $client, $client, [
            'api_key_id' => $apiKey->id,
        ]);

        return $this->result(
            false,
            'API key created. Copy it now; it will not be shown again.',
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

        $plain = $this->makeToken();

        $apiKey = DB::transaction(function () use ($client, $plain) {
            ApiKey::query()
                ->where('client_id', $client->id)
                ->where('status', 'active')
                ->update(['status' => 'inactive']);

            return ApiKey::create($this->keyAttributes($client, $plain));
        });

        $this->activity()->record('API key regenerated', 'api_key', 'updated', 'client', $client, $client, [
            'api_key_id' => $apiKey->id,
            'replaced_api_key_id' => $current->id,
        ]);

        return $this->result(
            false,
            'API key regenerated. Copy it now; it will not be shown again. The previous key is inactive.',
            ApiCode::SUCCESS,
            ApiReason::SUCCESS,
            $this->present($apiKey, $plain),
        );
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
            'token' => ApiKey::hashToken($plain),
            'token_prefix' => substr($plain, 0, 12),
            'status' => 'active',
        ];
    }
}
