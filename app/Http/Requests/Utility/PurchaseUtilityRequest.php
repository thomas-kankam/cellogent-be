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
 * Pay mode toggle: method=wallet | momo (card still accepted for hosted Paystack).
 * `network` on the item is the airtime/telco network — not a payment field.
 * Paystack/Kairos do not need a MoMo network code; omit momo_network.
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
            // Payer MSISDN when method=momo (defaults to the client's profile phone).
            'phone_number' => ['nullable', 'string', GhanaPhone::rule()],
            // Optional / ignored by Paystack & Kairos. Do not confuse with airtime `network`.
            'momo_network' => ['nullable', 'string', 'max:50'],
        ]);
    }
}
