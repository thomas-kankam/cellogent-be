<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RewardDisbursement;
use App\Services\RewardDisbursementService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardDisbursementController extends Controller
{
    public function __construct(protected RewardDisbursementService $disbursements) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->disbursements->index(null, $request->only(['status', 'source_type', 'source_id', 'reward_type', 'provider', 'phone', 'client_id', 'from', 'to']));

        return $this->paginatedApiResponse('Reward payouts loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query), fn (RewardDisbursement $row) => $this->disbursements->present($row, true));
    }

    public function show(RewardDisbursement $disbursement): JsonResponse
    {
        return $this->apiResponse(false, 'Reward payout loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->disbursements->present($disbursement, true));
    }
}
