<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\SubscribeRequest;
use App\Models\Plan;
use App\Models\SubscriptionArchive;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptions,
        protected PaymentService $payments,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $subscription = $this->subscriptions->currentFor($request->user());

        if (! $subscription) {
            return $this->apiResponse(true, 'No active subscription.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'Subscription loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->subscriptions->present($subscription));
    }

    public function archives(Request $request): JsonResponse
    {
        $paginator = $this->paginateQuery(
            $request,
            SubscriptionArchive::query()->where('client_id', $request->user()->id)->latest('id'),
        );

        return $this->paginatedApiResponse(
            'Subscription archives loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn (SubscriptionArchive $archive) => $this->subscriptions->presentArchive($archive),
        );
    }

    public function store(SubscribeRequest $request): JsonResponse
    {
        $plan = Plan::query()->find($request->integer('plan_id'));

        if (! $plan || $plan->status !== 'active') {
            return $this->apiResponse(true, 'Plan not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $type = $request->input('type', 'monthly');

        return $this->respondFrom($this->payments->initiate($request->user(), [
            'type' => 'sms_units',
            'name' => $plan->name.' plan',
            'unit_price' => $plan->price(),
            'quantity' => 1,
            'network' => $request->input('network'),
            'method' => $request->input('method', 'momo'),
            'meta_data' => [
                'plan_id' => $plan->id,
                'type' => $type,
            ],
        ]));
    }
}
