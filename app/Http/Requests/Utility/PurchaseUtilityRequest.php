<?php

namespace App\Http\Requests\Utility;

use App\Services\Utilities\UtilityService;
use App\Support\GhanaPhone;
use Illuminate\Validation\Rule;

/**
 * Purchase one item, many recipients for one package (`beneficiaries` CSV/array ≤50),
 * mixed `items[]` (≤50), or phone `contact_group_ids` (data/mashup/airtime only).
 *
 * beneficiaries by service:
 *  - data / mashup / airtime / results → Ghana mobiles
 *  - ecg → meter / account numbers
 *  - tv → smartcard numbers
 *
 * Pay mode toggle: method=wallet | momo (card still accepted for hosted Paystack).
 */
class PurchaseUtilityRequest extends QuoteUtilityRequest
{
    public function rules(): array
    {
        // array_merge so purchase-specific beneficiary / phone rules override the quote defaults.
        return array_merge(parent::rules(), [
            'beneficiary' => ['required_without_all:items,contact_group_ids,beneficiaries', 'nullable', 'string', 'max:30'],
            // Validated per service in UtilityService (phones vs meters/smartcards).
            'beneficiaries.*' => ['required', 'string', 'max:30'],
            'items.*.beneficiary' => ['required', 'string', 'max:30'],
            'account_name' => ['nullable', 'string', 'max:120'],
            'items.*.account_name' => ['nullable', 'string', 'max:120'],
            // ECG: shared customer mobile for bulk meters (defaults to the buyer's own).
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
