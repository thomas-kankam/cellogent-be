<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\ApiFormRequest;
use App\Support\GhanaPhone;

/**
 * Fund the wallet via the primary enabled payment gateway (priority 1).
 * Clients do not pick method — you cannot top up a wallet with wallet balance.
 * phone_number is optional (defaults to the client's profile); needed when Kairos is primary.
 */
class StoreTopUpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'phone_number' => ['nullable', 'string', GhanaPhone::rule()],
        ];
    }
}
