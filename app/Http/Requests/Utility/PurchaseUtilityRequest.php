<?php

namespace App\Http\Requests\Utility;

use App\Services\Utilities\UtilityService;
use App\Support\GhanaPhone;
use Illuminate\Validation\Rule;

/**
 * Purchase one item, many phones for one package (`beneficiaries`), mixed `items[]` (≤50),
 * or a contact group. Each recipient needs a phone (data/mashup/airtime/results),
 * meter (ECG), or smartcard (TV).
 *
 * Pay with method=wallet|momo|card (Paystack/Kairos via checkout).
 */
class PurchaseUtilityRequest extends QuoteUtilityRequest
{
    public function rules(): array
    {
        // array_merge so purchase-specific beneficiary / phone rules override the quote defaults.
        return array_merge(parent::rules(), [
            'beneficiary' => ['required_without_all:items,contact_group_ids,beneficiaries', 'nullable', 'string', 'max:30'],
            'beneficiaries.*' => ['required', 'string', GhanaPhone::rule()],
            'items.*.beneficiary' => ['required', 'string', 'max:30'],
            'account_name' => ['nullable', 'string', 'max:120'],
            'items.*.account_name' => ['nullable', 'string', 'max:120'],
            // ECG only: the customer's mobile number (defaults to the buyer's own).
            'customer_phone' => ['nullable', 'string', GhanaPhone::rule()],
            'items.*.customer_phone' => ['nullable', 'string', GhanaPhone::rule()],
            'method' => ['nullable', 'string', Rule::in(UtilityService::METHODS)],
            // Mobile money number / network used to pay when method is momo.
            'phone_number' => ['nullable', 'string', GhanaPhone::rule()],
            'momo_network' => ['nullable', 'string', 'max:50'],
        ]);
    }
}
