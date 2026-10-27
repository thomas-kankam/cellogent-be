<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\ApiFormRequest;

class StoreTopUpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'network' => ['nullable', 'string', 'max:50'],
            'method' => ['nullable', 'string', 'in:momo,card,bank,wallet'],
        ];
    }
}
