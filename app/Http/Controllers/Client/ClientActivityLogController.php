<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientActivityLogController extends Controller
{
    public function __construct(protected ActivityLogService $logs)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->paginateQuery(
            $request,
            $this->logs->query(array_merge(
                $request->only(['module', 'action']),
                ['receiver' => $request->user()],
            )),
        );

        return $this->paginatedApiResponse(
            'Activity logs loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn ($log) => $this->logs->present($log),
        );
    }
}
