<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignSubscriptionRequest;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionArchive;
use App\Services\SubscriptionService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptions)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Subscription::query()->latest('id');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Subscriptions loaded.', ApiReason::SUCCESS, $paginator, fn (Subscription $row) => $this->subscriptions->present($row));
    }

    public function forClient(Client $client): JsonResponse
    {
        $subscription = $this->subscriptions->currentFor($client);

        if (! $subscription) {
            return $this->apiResponse(true, 'No active subscription.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'Subscription loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->subscriptions->present($subscription));
    }

    public function assign(AssignSubscriptionRequest $request, Client $client): JsonResponse
    {
        $plan = Plan::query()->find($request->integer('plan_id'));

        if (! $plan) {
            return $this->apiResponse(true, 'Plan not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->respondFrom($this->subscriptions->activatePlan(
            $client,
            $plan,
            $request->input('type', 'monthly'),
            null,
            'admin-assign',
            'assigned',
            $request->user(),
        ));
    }

    public function archives(Request $request): JsonResponse
    {
        $query = SubscriptionArchive::query()->latest('id');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse(
            'Subscription archives loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn (SubscriptionArchive $archive) => $this->subscriptions->presentArchive($archive),
        );
    }
}
