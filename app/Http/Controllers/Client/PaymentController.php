<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $payments)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()->where('client_id', $request->user()->id)->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('purpose')) {
            $query->where('purpose', $request->string('purpose'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Payments loaded.', ApiReason::SUCCESS, $paginator, fn (Payment $payment) => $this->payments->present($payment));
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        if ((int) $payment->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'Payment not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->apiResponse(false, 'Payment loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->payments->present($payment));
    }
}
