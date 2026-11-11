<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use App\Services\RewardService;
use App\Services\Utilities\UtilityService;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    public function __construct(protected RewardService $rewards, protected UtilityService $utilities) {}

    /**
     * Reward picker: data bundles by network (pick a `package_id`), airtime networks and amounts,
     * and money. Prices are what the client's wallet pays per reward.
     */
    public function catalog(): JsonResponse
    {
        return $this->respondFrom($this->utilities->rewardCatalog());
    }

    /**
     * Rewards a client can attach to a campaign or survey (in stock only).
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->rewards->index($request->only(['type', 'network', 'search']), true);

        return $this->paginatedApiResponse('Rewards loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query, 50), fn (Reward $reward) => $this->rewards->presentForClient($reward));
    }
}
