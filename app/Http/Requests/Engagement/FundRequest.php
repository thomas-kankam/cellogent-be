<?php

namespace App\Http\Requests\Engagement;

use App\Http\Requests\ApiFormRequest;
use App\Support\GhanaPhone;

/**
 * Pay for a campaign's or survey's rewards. Wallet reserves the money at once;
 * momo/card start a checkout. Paystack/Kairos do not need `network`.
 */
class FundRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'method' => ['nullable', 'string', 'in:wallet,momo,card'],
            'phone_number' => ['nullable', 'string', GhanaPhone::rule()],
            'network' => ['nullable', 'string', 'max:50'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0.01'],
        ];
    }
}
