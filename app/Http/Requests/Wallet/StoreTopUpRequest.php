<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\ApiFormRequest;
use App\Support\GhanaPhone;

class StoreTopUpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', 'string', 'in:momo,card'],
            'phone_number' => ['nullable', 'string', GhanaPhone::rule()],
            'network' => ['nullable', 'string', 'max:50'],
        ];
    }
}
