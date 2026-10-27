<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\PlanService;
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
        $paginator = $this->paginateQuery(
            $request,
            Plan::query()->where('status', 'active')->orderBy('name'),
        );

        return $this->paginatedApiResponse('Plans loaded.', ApiReason::SUCCESS, $paginator, fn (Plan $plan) => $this->plans->present($plan));
    }
}
