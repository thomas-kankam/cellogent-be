<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Margin\StoreMarginRequest;
use App\Models\ProductMargin;
use App\Services\MarginService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profit margins added on top of what the provider charges (per product, network or bundle).
 */
class MarginController extends Controller
{
    public function __construct(protected MarginService $margins) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->margins->index($request->only(['product_type', 'network', 'active']));

        return $this->paginatedApiResponse('Margins loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query), fn (ProductMargin $margin) => $this->margins->present($margin));
    }

    public function options(): JsonResponse
    {
        return $this->apiResponse(false, 'Margin options loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->margins->options());
    }

    public function show(ProductMargin $margin): JsonResponse
    {
        return $this->respondFrom($this->margins->show($margin));
    }

    public function store(StoreMarginRequest $request): JsonResponse
    {
        return $this->respondFrom($this->margins->create($request->user(), $request->validated()));
    }

    public function update(StoreMarginRequest $request, ProductMargin $margin): JsonResponse
    {
        return $this->respondFrom($this->margins->update($request->user(), $margin, $request->validated()));
    }

    public function destroy(Request $request, ProductMargin $margin): JsonResponse
    {
        return $this->respondFrom($this->margins->delete($request->user(), $margin));
    }
}
