<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\ApiFormRequest;
use App\Support\GhanaPhone;

/**
 * Fund the wallet via gateway checkout.
 * Paystack / Kairos do not need a MoMo network — omit `network`.
 * method=momo needs a phone (falls back to the client's profile number).
 * method=card opens hosted checkout (payer can still pick MoMo on Paystack).
 */
class StoreTopUpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', 'string', 'in:momo,card'],
            'phone_number' => ['nullable', 'string', GhanaPhone::rule()],
            // Optional / ignored by Paystack & Kairos — kept for older clients.
            'network' => ['nullable', 'string', 'max:50'],
        ];
    }
}
