<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreTopUpRequest;
use App\Models\WalletTopUp;
use App\Services\PaymentService;
use App\Services\WalletService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        protected WalletService $wallets,
        protected PaymentService $payments,
    ) {
    }

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

    public function storeTopUp(StoreTopUpRequest $request): JsonResponse
    {
        if ($request->input('method') === 'wallet') {
            return $this->apiResponse(true, 'Cannot top up a wallet with wallet balance.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        return $this->respondFrom($this->payments->initiate($request->user(), [
            'type' => 'wallet_top_up',
            'name' => 'Wallet top up',
            'unit_price' => $request->input('amount'),
            'quantity' => 1,
            'network' => $request->input('network'),
            'method' => $request->input('method', 'momo'),
            'meta_data' => ['amount' => $request->input('amount')],
        ]));
    }
}
