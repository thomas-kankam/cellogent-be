<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\ActivityLogService;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __construct(protected ActivityLogService $logs)
    {
    }

    /**
     * All activity across the product. Filter with module, action, actor.
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->paginateQuery($request, $this->logs->query($request->only(['module', 'action', 'actor'])));

        return $this->paginatedApiResponse(
            'Activity logs loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn ($log) => $this->logs->present($log),
        );
    }

    /**
     * Timeline for one client: their OTP, onboarding, sender ID requests, who approved, etc.
     */
    public function forClient(Request $request, Client $client): JsonResponse
    {
        $paginator = $this->paginateQuery(
            $request,
            $this->logs->query(array_merge($request->only(['module', 'action']), ['receiver' => $client])),
        );

        return $this->paginatedApiResponse(
            'Client activity loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn ($log) => $this->logs->present($log),
        );
    }
}
