<?php

namespace App\Http\Requests\Utility;

use App\Services\Utilities\UtilityService;
use App\Support\GhanaPhone;
use Illuminate\Validation\Rule;

/**
 * Purchase one item or up to 50 (`items[]`). Each item also needs who receives it:
 * a phone (data, mashup, airtime, results), a meter / account number (ECG) or a smartcard (TV).
 */
class PurchaseUtilityRequest extends QuoteUtilityRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'beneficiary' => ['required_without_all:items,contact_group_ids', 'nullable', 'string', 'max:30'],
            'items.*.beneficiary' => ['required', 'string', 'max:30'],
            'account_name' => ['nullable', 'string', 'max:120'],
            'items.*.account_name' => ['nullable', 'string', 'max:120'],
            // ECG only: the customer's mobile number (defaults to the buyer's own).
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'items.*.customer_phone' => ['nullable', 'string', 'max:20'],
            'method' => ['nullable', 'string', Rule::in(UtilityService::METHODS)],
            // Mobile money number / network used to pay when method is momo.
            'phone_number' => ['nullable', 'string', GhanaPhone::rule()],
            'momo_network' => ['nullable', 'string', 'max:50'],
        ];
    }
}
