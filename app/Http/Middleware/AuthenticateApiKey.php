<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Client;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates public API calls with the client's active API key.
 * Send it as `x-api-key: cgk_...` or `Authorization: Bearer cgk_...`.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) ($request->header('x-api-key') ?: $request->bearerToken());

        if (! str_starts_with($token, 'cgk_')) {
            return $this->deny('A valid API key is required.');
        }

        $apiKey = ApiKey::query()
            ->where('token', ApiKey::hashToken($token))
            ->where('status', 'active')
            ->first();

        if (! $apiKey) {
            return $this->deny('This API key is invalid or has been regenerated.');
        }

        $client = Client::query()->find($apiKey->client_id);

        if (! $client) {
            return $this->deny('This API key is invalid or has been regenerated.');
        }

        if ($client->status !== 'active') {
            return ApiResponse::make(true, 'This account is not active.', ApiCode::FORBIDDEN, ApiReason::ACCOUNT_INACTIVE);
        }

        if (Cache::add("api-key:touched:{$apiKey->id}", true, 60)) {
            $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->setUserResolver(fn () => $client);
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    protected function deny(string $message): Response
    {
        return ApiResponse::make(true, $message, ApiCode::UNAUTHENTICATED, ApiReason::UNAUTHENTICATED);
    }
}
