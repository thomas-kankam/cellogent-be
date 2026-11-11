<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\RewardDisbursement;
use App\Services\RewardDisbursementService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who got which campaign / survey reward, and whether it was paid.
 */
class RewardDisbursementController extends Controller
{
    public function __construct(protected RewardDisbursementService $disbursements) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->disbursements->index($request->user(), $request->only(['status', 'source_type', 'source_id', 'reward_type', 'phone', 'from', 'to']));

        return $this->paginatedApiResponse('Reward payouts loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query), fn (RewardDisbursement $row) => $this->disbursements->present($row));
    }

    public function show(Request $request, RewardDisbursement $disbursement): JsonResponse
    {
        if ((int) $disbursement->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'Reward payout not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'Reward payout loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->disbursements->present($disbursement));
    }
}
