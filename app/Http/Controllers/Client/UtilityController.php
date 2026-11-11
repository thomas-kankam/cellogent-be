<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Utility\LookupUtilityRequest;
use App\Http\Requests\Utility\PurchaseUtilityRequest;
use App\Http\Requests\Utility\QuoteUtilityRequest;
use App\Models\UtilityOrder;
use App\Services\Utilities\UtilityService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UtilityController extends Controller
{
    public function __construct(protected UtilityService $utilities) {}

    public function catalog(): JsonResponse
    {
        return $this->respondFrom($this->utilities->catalog());
    }

    /**
     * Same catalogue the reward picker needs (data plans by network + airtime networks).
     */
    public function rewardCatalog(): JsonResponse
    {
        return $this->respondFrom($this->utilities->rewardCatalog());
    }

    public function quote(QuoteUtilityRequest $request): JsonResponse
    {
        return $this->respondFrom($this->utilities->quote($request->validated()));
    }

    public function lookup(LookupUtilityRequest $request): JsonResponse
    {
        return $this->respondFrom($this->utilities->lookup($request->validated('service'), $request->validated()));
    }

    public function purchase(PurchaseUtilityRequest $request): JsonResponse
    {
        return $this->respondFrom($this->utilities->purchase($request->user(), $request->validated()));
    }

    public function orders(Request $request): JsonResponse
    {
        $query = $this->utilities->orders($request->user(), $request->only(['status', 'service', 'product_type', 'batch_id', 'from', 'to']));

        return $this->paginatedApiResponse('Utility orders loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query), fn (UtilityOrder $order) => $this->utilities->present($order));
    }

    public function show(Request $request, UtilityOrder $order): JsonResponse
    {
        if ((int) $order->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'Order not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->utilities->status($request->user(), $order));
    }
}
