<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\ApiKeyService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function __construct(protected ApiKeyService $keys) {}

    public function index(Request $request): JsonResponse
    {
        $key = $this->keys->activeFor($request->user());

        if (! $key) {
            return $this->apiResponse(true, 'No active API key.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'Active API key loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->keys->present($key));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->respondFrom($this->keys->create($request->user()));
    }

    public function regenerate(Request $request): JsonResponse
    {
        return $this->respondFrom($this->keys->regenerate($request->user()));
    }
}
