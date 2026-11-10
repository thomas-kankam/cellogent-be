<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\Payments\CheckoutService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected CheckoutService $checkout,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Order::query()->with('items')->where('client_id', $request->user()->id)->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Orders loaded.', ApiReason::SUCCESS, $paginator, fn (Order $order) => $this->orders->present($order));
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        if ((int) $order->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'Order not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'Order loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->orders->present($order));
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        return $this->respondFrom($this->checkout->start($request->user(), $request->purpose(), $request->validated()));
    }
}
