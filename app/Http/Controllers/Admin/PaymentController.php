<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\PaymentVerifier;
use App\Services\PaymentService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected PaymentVerifier $verifier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()->latest('id');

        foreach (['status', 'purpose', 'gateway', 'method'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->string($field));
            }
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        if ($request->filled('reference')) {
            $reference = (string) $request->string('reference');
            $query->where(fn ($q) => $q->where('reference_id', $reference)->orWhere('gateway_reference', $reference));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from')?->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to')?->endOfDay());
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse('Payments loaded.', ApiReason::SUCCESS, $paginator, fn (Payment $payment) => $this->payments->presentForAdmin($payment));
    }

    public function show(Payment $payment): JsonResponse
    {
        return $this->apiResponse(false, 'Payment loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->payments->presentForAdmin($payment));
    }

    /**
     * Re-check the status with the gateway (also settles failed payments that were later paid).
     */
    public function verify(Payment $payment): JsonResponse
    {
        if (! $payment->gateway || $payment->gateway === 'wallet') {
            return $this->apiResponse(true, 'This payment was not made through a gateway.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $this->verifier->verify($payment);

        return $this->apiResponse(false, 'Payment status checked.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->payments->presentForAdmin($payment->fresh()));
    }

    public function complete(Request $request, Payment $payment): JsonResponse
    {
        return $this->respondFrom($this->payments->complete($payment, $request->user()));
    }

    public function fail(Request $request, Payment $payment): JsonResponse
    {
        $reason = trim((string) $request->input('reason')) ?: 'Marked as failed by admin.';

        return $this->respondFrom($this->payments->fail($payment, $request->user(), $reason));
    }
}
