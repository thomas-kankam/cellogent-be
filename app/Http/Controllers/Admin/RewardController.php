<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reward\StoreRewardRequest;
use App\Http\Requests\Reward\UpdateRewardStatusRequest;
use App\Models\Reward;
use App\Services\RewardService;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    public function __construct(protected RewardService $rewards) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->rewards->index($request->only(['status', 'type', 'network', 'search']));

        return $this->paginatedApiResponse('Rewards loaded.', ApiReason::SUCCESS, $this->paginateQuery($request, $query), fn (Reward $reward) => $this->rewards->present($reward));
    }

    public function show(Reward $reward): JsonResponse
    {
        return $this->respondFrom($this->rewards->show($reward));
    }

    public function store(StoreRewardRequest $request): JsonResponse
    {
        return $this->respondFrom($this->rewards->create($request->user(), $request->validated()));
    }

    public function update(StoreRewardRequest $request, Reward $reward): JsonResponse
    {
        return $this->respondFrom($this->rewards->update($request->user(), $reward, $request->validated()));
    }

    public function updateStatus(UpdateRewardStatusRequest $request, Reward $reward): JsonResponse
    {
        return $this->respondFrom($this->rewards->updateStatus($request->user(), $reward, $request->validated('status')));
    }

    public function destroy(Request $request, Reward $reward): JsonResponse
    {
        return $this->respondFrom($this->rewards->delete($request->user(), $reward));
    }
}
