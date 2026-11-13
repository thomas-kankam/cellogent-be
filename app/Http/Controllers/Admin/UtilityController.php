<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

    /**
     * Admin catalogue: Swift's api_price, our sell price and the profit on each plan.
     */
    public function catalog(): JsonResponse
    {
        return $this->respondFrom($this->utilities->catalog(true));
    }

    /**
     * Price breakdown with cost and profit (what a client would be quoted).
     */
    public function quote(QuoteUtilityRequest $request): JsonResponse
    {
        return $this->respondFrom($this->utilities->quote(null, $request->validated(), true));
    }

    public function balance(): JsonResponse
    {
        return $this->respondFrom($this->utilities->providerBalance());
    }

    /**
     * Profit on completed utility orders. Filters: from, to, product_type, client_id, funding_method.
     */
    public function profit(Request $request): JsonResponse
    {
        return $this->respondFrom($this->utilities->profitReport($request->only(['from', 'to', 'product_type', 'client_id', 'funding_method'])));
    }

    public function orders(Request $request): JsonResponse
    {
        $query = $this->utilities->orders(null, $request->only(['status', 'service', 'product_type', 'batch_id', 'funding_method', 'client_id', 'from', 'to']));

        return $this->paginatedApiResponse('Utility orders loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query), fn (UtilityOrder $order) => $this->utilities->presentForAdmin($order));
    }

    /**
     * Admin batch tracker (includes cost/profit). Filter orders list with ?batch_id= as well.
     */
    public function batch(string $batch): JsonResponse
    {
        return $this->respondFrom($this->utilities->batch(null, $batch));
    }

    public function show(UtilityOrder $order): JsonResponse
    {
        return $this->apiResponse(false, 'Utility order loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->utilities->presentForAdmin($order));
    }
}
