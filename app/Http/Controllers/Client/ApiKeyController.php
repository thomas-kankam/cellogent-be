<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKey\RegenerateApiKeyRequest;
use App\Http\Requests\ApiKey\StoreApiKeyRequest;
use App\Http\Requests\ApiKey\UpdateApiKeyRequest;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function __construct(protected ApiKeyService $keys)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->paginateQuery(
            $request,
            ApiKey::query()->where('client_id', $request->user()->id)->latest('id'),
        );

        return $this->paginatedApiResponse('API keys loaded.', ApiReason::SUCCESS, $paginator, fn (ApiKey $row) => $this->keys->present($row));
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        return $this->respondFrom($this->keys->create($request->user(), $request->validated()));
    }

    public function show(Request $request, ApiKey $apiKey): JsonResponse
    {
        if ((int) $apiKey->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'API key not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'API key loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->keys->present($apiKey));
    }

    public function update(UpdateApiKeyRequest $request, ApiKey $apiKey): JsonResponse
    {
        if ((int) $apiKey->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'API key not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->keys->update($request->user(), $apiKey, $request->validated()));
    }

    public function regenerate(RegenerateApiKeyRequest $request, ApiKey $apiKey): JsonResponse
    {
        if ((int) $apiKey->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'API key not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->keys->regenerate($request->user(), $apiKey, $request->validated()));
    }

    public function destroy(Request $request, ApiKey $apiKey): JsonResponse
    {
        if ((int) $apiKey->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'API key not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->keys->delete($request->user(), $apiKey));
    }
}
