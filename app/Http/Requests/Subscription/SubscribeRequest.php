<?php

namespace App\Http\Requests\Subscription;

use App\Http\Requests\ApiFormRequest;
use App\Support\GhanaPhone;

class SubscribeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer'],
            'months' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'in:momo,card,wallet'],
            'phone_number' => ['nullable', 'string', GhanaPhone::rule()],
            'network' => ['nullable', 'string', 'max:50'],
        ];
    }
}
