<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreTopUpRequest;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use App\Services\Payments\CheckoutService;
use App\Services\WalletService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        protected WalletService $wallets,
        protected CheckoutService $checkout,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->wallets->forClient($request->user());

        return $this->apiResponse(false, 'Wallet loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->wallets->present($wallet));
    }

    public function topUps(Request $request): JsonResponse
    {
        $paginator = $this->paginateQuery(
            $request,
            WalletTopUp::query()->where('client_id', $request->user()->id)->latest('id'),
        );

        return $this->paginatedApiResponse(
            'Wallet top-ups loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn (WalletTopUp $topUp) => $this->wallets->presentTopUp($topUp),
        );
    }

    public function transactions(Request $request): JsonResponse
    {
        $query = WalletTransaction::query()
            ->with('client')
            ->where('client_id', $request->user()->id)
            ->latest('id');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        if ($request->filled('purpose')) {
            $query->where('purpose', $request->string('purpose'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse(
            'Wallet transactions loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn (WalletTransaction $transaction) => $this->wallets->presentTransaction($transaction),
        );
    }

    public function storeTopUp(StoreTopUpRequest $request): JsonResponse
    {
        // No method — CheckoutService routes to the primary enabled gateway (never wallet→wallet).
        return $this->respondFrom($this->checkout->start($request->user(), 'wallet_top_up', $request->validated()));
    }
}
