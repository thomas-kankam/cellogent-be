<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\ApiFormRequest;
use App\Services\Payments\CheckoutService;
use App\Support\GhanaPhone;

/**
 * Generic checkout. Prices are always looked up on the server.
 */
class StoreOrderRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'purpose' => ['required_without:type', 'nullable', 'in:'.implode(',', CheckoutService::PURPOSES)],
            'type' => ['nullable', 'in:'.implode(',', CheckoutService::PURPOSES)],
            'amount' => ['required_if:purpose,wallet_top_up', 'nullable', 'numeric', 'min:1'],
            'plan_id' => ['required_if:purpose,sms_units', 'nullable', 'integer'],
            'months' => ['required_if:purpose,sms_units', 'nullable', 'integer', 'min:1'],
            'method' => ['required', 'string', 'in:'.implode(',', CheckoutService::METHODS)],
            'phone_number' => ['nullable', 'string', GhanaPhone::rule()],
            'network' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function purpose(): string
    {
        return (string) ($this->input('purpose') ?? $this->input('type'));
    }
}
