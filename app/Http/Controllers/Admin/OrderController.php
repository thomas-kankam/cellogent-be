<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(protected OrderService $orders)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Order::query()->with('items')->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Orders loaded.', ApiReason::SUCCESS, $paginator, fn (Order $order) => $this->orders->present($order));
    }

    public function show(Order $order): JsonResponse
    {
        return $this->apiResponse(false, 'Order loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->orders->present($order));
    }
}
