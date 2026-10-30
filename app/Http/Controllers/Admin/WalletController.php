<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Wallet;
use App\Models\WalletTopUp;
use App\Services\WalletService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(protected WalletService $wallets)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Wallet::query()->with('client')->latest('id');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Wallets loaded.', ApiReason::SUCCESS, $paginator, fn (Wallet $wallet) => $this->wallets->present($wallet));
    }

    public function forClient(Client $client): JsonResponse
    {
        $wallet = $this->wallets->forClient($client);

        return $this->apiResponse(false, 'Wallet loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->wallets->present($wallet));
    }

    public function topUps(Request $request): JsonResponse
    {
        $query = WalletTopUp::query()->latest('id');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Wallet top-ups loaded.', ApiReason::SUCCESS, $paginator, fn (WalletTopUp $topUp) => $this->wallets->presentTopUp($topUp));
    }
}
