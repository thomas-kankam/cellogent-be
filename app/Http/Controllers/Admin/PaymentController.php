<?php

namespace App\Http\Controllers\Admin;

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
        $query = Payment::query()->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('purpose')) {
            $query->where('purpose', $request->string('purpose'));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Payments loaded.', ApiReason::SUCCESS, $paginator, fn (Payment $payment) => $this->payments->present($payment));
    }

    public function show(Payment $payment): JsonResponse
    {
        return $this->apiResponse(false, 'Payment loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->payments->present($payment));
    }

    public function complete(Request $request, Payment $payment): JsonResponse
    {
        return $this->respondFrom($this->payments->complete($payment, $request->user()));
    }

    public function fail(Request $request, Payment $payment): JsonResponse
    {
        return $this->respondFrom($this->payments->fail($payment, $request->user()));
    }
}
