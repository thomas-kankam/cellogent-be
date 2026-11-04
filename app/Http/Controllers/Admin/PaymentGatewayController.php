<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\GatewayManager;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin-only: which gateway is primary, which are enabled (fallbacks), and the charge rates.
 */
class PaymentGatewayController extends Controller
{
    use LogsActivity;

    public function __construct(protected GatewayManager $gateways) {}

    public function index(): JsonResponse
    {
        $list = $this->gateways->all()->map(fn (PaymentGateway $g) => $this->gateways->present($g))->values();

        return $this->apiResponse(false, 'Payment gateways loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $list->all());
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $gateway = $this->find($code);

        if (! $gateway) {
            return $this->apiResponse(true, 'Payment gateway not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $validator = Validator::make($request->all(), [
            'is_enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->apiResponse(true, 'Validation failed.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        if (($data['is_enabled'] ?? true) === false) {
            $othersEnabled = PaymentGateway::query()->where('code', '!=', $gateway->code)->where('is_enabled', true)->exists();

            if (! $othersEnabled) {
                return $this->apiResponse(true, 'At least one payment gateway must stay enabled.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
            }
        }

        $before = $gateway->only(['is_enabled', 'priority']);
        $gateway->update($data + ['updated_by' => $request->user()->id]);

        $this->activity()->record('Payment gateway updated', 'payment_gateway', 'updated', 'admin', $request->user(), null, [
            'gateway' => $gateway->code,
            'before' => $before,
            'after' => $gateway->only(['is_enabled', 'priority']),
        ]);

        return $this->apiResponse(false, 'Payment gateway updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->gateways->present($gateway->fresh()));
    }

    /**
     * Make this gateway the one tried first; the others become fallbacks in their current order.
     */
    public function primary(Request $request, string $code): JsonResponse
    {
        $gateway = $this->find($code);

        if (! $gateway) {
            return $this->apiResponse(true, 'Payment gateway not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $gateway->update(['is_enabled' => true, 'consecutive_failures' => 0, 'updated_by' => $request->user()->id]);
        $this->gateways->makePrimary($gateway->code, $request->user());

        $this->activity()->record('Primary payment gateway changed', 'payment_gateway', 'updated', 'admin', $request->user(), null, [
            'gateway' => $gateway->code,
        ]);

        return $this->index();
    }

    public function settings(): JsonResponse
    {
        return $this->apiResponse(false, 'Payment settings loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->presentSettings());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'charges' => ['required', 'array'],
            'charges.wallet_top_up' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'charges.sms_units' => ['nullable', 'numeric', 'min:0', 'max:50'],
        ]);

        if ($validator->fails()) {
            return $this->apiResponse(true, 'Validation failed.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, $validator->errors()->toArray());
        }

        $before = $this->presentSettings();

        foreach (CheckoutService::PURPOSES as $purpose) {
            if (array_key_exists($purpose, $request->input('charges', []))) {
                $value = $request->input("charges.{$purpose}");
                Setting::put("charges.{$purpose}", $value === null ? 0 : round((float) $value, 2), $request->user()->id);
            }
        }

        $after = $this->presentSettings();

        $this->activity()->record('Payment charges updated', 'settings', 'updated', 'admin', $request->user(), null, [
            'before' => $before['charges'],
            'after' => $after['charges'],
        ]);

        return $this->apiResponse(false, 'Payment settings updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $after);
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentSettings(): array
    {
        $charges = [];

        foreach (CheckoutService::PURPOSES as $purpose) {
            $charges[$purpose] = CheckoutService::chargePercent($purpose);
        }

        return [
            'charges' => $charges,
            'charge_type' => 'percent',
            'currency' => config('payments.currency', 'GHS'),
            'wallet_top_up' => config('payments.wallet_top_up'),
        ];
    }

    protected function find(string $code): ?PaymentGateway
    {
        return $this->gateways->all()->firstWhere('code', $code);
    }
}
