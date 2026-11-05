<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\ActivityLogService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __construct(protected ActivityLogService $logs) {}

    /**
     * Dropdown catalogues: modules, actions, actors (admin|client|system).
     */
    public function filters(): JsonResponse
    {
        return $this->apiResponse(false, 'Activity log filters loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->logs->filters());
    }

    /**
     * All activity. Filter with query params:
     * module, action, actor (admin|client|system), search, from, to, admin_id, client_id
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->listFilters($request);

        $paginator = $this->paginateQuery($request, $this->logs->query($filters));

        return $this->paginatedApiResponse(
            'Activity logs loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn ($log) => $this->logs->present($log),
        );
    }

    /**
     * Timeline for one client (as receiver): their OTP, onboarding, sender IDs, approvals, etc.
     * Same filters as index except actor defaults are unconstrained; pass actor=admin|client to narrow.
     */
    public function forClient(Request $request, Client $client): JsonResponse
    {
        $filters = $this->listFilters($request);
        $filters['receiver'] = $client;

        $paginator = $this->paginateQuery($request, $this->logs->query($filters));

        return $this->paginatedApiResponse(
            'Client activity loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn ($log) => $this->logs->present($log),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function listFilters(Request $request): array
    {
        $filters = $request->only(['module', 'action', 'actor', 'search']);

        if ($request->filled('admin_id')) {
            $filters['admin_id'] = $request->integer('admin_id');
        }
        if ($request->filled('client_id')) {
            $filters['client_id'] = $request->integer('client_id');
        }
        if ($request->filled('from')) {
            $filters['from'] = $request->date('from')?->startOfDay()->toDateTimeString();
        }
        if ($request->filled('to')) {
            $filters['to'] = $request->date('to')?->endOfDay()->toDateTimeString();
        }

        return array_filter($filters, fn ($v) => $v !== null && $v !== '');
    }
}
