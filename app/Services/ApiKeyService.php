<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\ApiKey;
use App\Models\Client;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Support\Str;

class ApiKeyService
{
    use LogsActivity;
    use ReturnsServiceResult;

    /**
     * @return array<string, mixed>
     */
    public function present(ApiKey $key, ?string $plainToken = null): array
    {
        $payload = [
            'id' => $key->id,
            'client_id' => $key->client_id,
            'name' => $key->name,
            'description' => $key->description,
            'prefix' => $key->prefix,
            'expires_at' => optional($key->expires_at)->toDateTimeString(),
            'status' => $key->status,
            'last_used_at' => optional($key->last_used_at)->toDateTimeString(),
            'created_at' => optional($key->created_at)->toDateTimeString(),
        ];

        if ($plainToken) {
            $payload['token'] = $plainToken;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function create(Client $client, array $input): array
    {
        [$plain, $prefix, $hash] = $this->makeToken();

        $key = ApiKey::create([
            'client_id' => $client->id,
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'prefix' => $prefix,
            'token' => $hash,
            'expires_at' => $input['expires_at'] ?? null,
            'status' => 'active',
        ]);

        $this->activity()->record('API key created', 'api_key', 'created', 'client', $client, $client, [
            'api_key_id' => $key->id,
            'name' => $key->name,
        ]);

        return $this->result(false, 'API key created. Copy the token now; it is not shown again.', ApiCode::CREATED, ApiReason::CREATED, $this->present($key, $plain));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function update(Client $client, ApiKey $key, array $input): array
    {
        $key->update([
            'name' => $input['name'] ?? $key->name,
            'description' => array_key_exists('description', $input) ? $input['description'] : $key->description,
            'expires_at' => array_key_exists('expires_at', $input) ? $input['expires_at'] : $key->expires_at,
            'status' => $input['status'] ?? $key->status,
        ]);

        return $this->result(false, 'API key updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($key->fresh()));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function regenerate(Client $client, ApiKey $key, array $input = []): array
    {
        [$plain, $prefix, $hash] = $this->makeToken();

        $key->update([
            'prefix' => $prefix,
            'token' => $hash,
            'expires_at' => array_key_exists('expires_at', $input) ? $input['expires_at'] : $key->expires_at,
            'status' => 'active',
        ]);

        $this->activity()->record('API key regenerated', 'api_key', 'updated', 'client', $client, $client, [
            'api_key_id' => $key->id,
        ]);

        return $this->result(false, 'API key regenerated. Copy the token now; it is not shown again.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($key->fresh(), $plain));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function delete(Client $client, ApiKey $key): array
    {
        $id = $key->id;
        $key->delete();

        $this->activity()->record('API key deleted', 'api_key', 'deleted', 'client', $client, $client, [
            'api_key_id' => $id,
        ]);

        return $this->result(false, 'API key deleted.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    protected function makeToken(): array
    {
        $plain = 'cgk_'.Str::random(48);
        $prefix = substr($plain, 0, 12);

        return [$plain, $prefix, hash('sha256', $plain)];
    }
}
