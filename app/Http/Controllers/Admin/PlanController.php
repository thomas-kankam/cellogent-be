<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plan\StorePlanRequest;
use App\Models\Plan;
use App\Services\PlanService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function __construct(protected PlanService $plans)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Plan::query()->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Plans loaded.', ApiReason::SUCCESS, $paginator, fn (Plan $plan) => $this->plans->present($plan));
    }

    public function show(Plan $plan): JsonResponse
    {
        return $this->apiResponse(false, 'Plan loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->plans->present($plan));
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        return $this->respondFrom($this->plans->create($request->user(), $request->validated()));
    }

    public function update(StorePlanRequest $request, Plan $plan): JsonResponse
    {
        return $this->respondFrom($this->plans->update($request->user(), $plan, $request->validated()));
    }
}
