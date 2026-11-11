<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\ApiFormRequest;
use App\Services\Payments\CheckoutService;
use App\Support\GhanaPhone;
use Illuminate\Validation\Rule;

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
            'amount' => [Rule::requiredIf(fn () => in_array($this->purpose(), ['wallet_top_up', ...CheckoutService::FUND_PURPOSES], true)), 'nullable', 'numeric', 'min:0.01'],
            'name' => ['nullable', 'string', 'max:120'],
            'campaign_id' => ['required_if:purpose,campaign_fund', 'nullable', 'integer', 'min:1'],
            'survey_id' => ['required_if:purpose,survey_fund', 'nullable', 'integer', 'min:1'],
            // Utility purposes (data_bundle, airtime, ecg, gotv, dstv, starttimes, mashup, results): see UtilityService::price().
            // Bulk purchases go through POST /client/utilities/purchase, which starts the utility_bulk checkout itself.
            'utility' => [Rule::requiredIf(fn () => in_array($this->purpose(), CheckoutService::UTILITY_PURPOSES, true)), 'nullable', 'array'],
            'utility.beneficiary' => ['nullable', 'string', 'max:30'],
            'utility.account_name' => ['nullable', 'string', 'max:120'],
            'utility.customer_phone' => ['nullable', 'string', 'max:20'],
            'utility.network' => ['nullable', 'string', 'max:50'],
            'utility.package_id' => ['nullable', 'string', 'max:120'],
            'utility.bill_type' => ['nullable', 'string', 'max:30'],
            'utility.checker_type' => ['nullable', 'string', 'max:30'],
            'utility.quantity' => ['nullable', 'integer', 'min:1'],
            'utility.amount' => ['nullable', 'numeric', 'min:0.01'],
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
