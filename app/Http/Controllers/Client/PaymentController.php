<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Models\Payment;
use App\Services\Payments\CheckoutService;
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
        protected CheckoutService $checkout,
        protected PaymentVerifier $verifier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()->where('client_id', $request->user()->id)->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('purpose')) {
            $query->where('purpose', $request->string('purpose'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from')?->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to')?->endOfDay());
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

    /**
     * Price breakdown (amount + charge = total) before paying.
     */
    public function quote(Request $request): JsonResponse
    {
        $purpose = (string) $request->input('purpose', '');
        $method = (string) $request->input('method', 'momo');

        if (! in_array($purpose, CheckoutService::PURPOSES, true) || ! in_array($method, CheckoutService::METHODS, true)) {
            return $this->apiResponse(true, 'Choose a valid purpose and payment method.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'purpose' => ['Allowed: '.implode(', ', CheckoutService::PURPOSES)],
                'method' => ['Allowed: '.implode(', ', CheckoutService::METHODS)],
            ]);
        }

        $result = $this->checkout->quote($purpose, $request->only(['amount', 'plan_id', 'months', 'name', 'campaign_id', 'survey_id', 'utility']), $method);
        unset($result['data']['meta']);

        return $this->respondFrom($result);
    }

    public function checkout(StoreOrderRequest $request): JsonResponse
    {
        return $this->respondFrom($this->checkout->start($request->user(), $request->purpose(), $request->validated()));
    }

    /**
     * "I have paid" button: asks the gateway right away instead of waiting for the poller.
     */
    public function verify(Request $request, Payment $payment): JsonResponse
    {
        if ((int) $payment->client_id !== (int) $request->user()->id) {
            return $this->apiResponse(true, 'Payment not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        if ($payment->status === Payment::PENDING) {
            $this->verifier->verify($payment);
            $payment->refresh();
        }

        $message = match ($payment->status) {
            Payment::PAID => 'Payment successful.',
            Payment::FAILED => $payment->failure_reason ?: 'Payment failed.',
            default => 'Payment is still pending. Approve the prompt on your phone or complete checkout.',
        };

        return $this->apiResponse(false, $message, ApiCode::SUCCESS, ApiReason::SUCCESS, $this->payments->present($payment));
    }
}
