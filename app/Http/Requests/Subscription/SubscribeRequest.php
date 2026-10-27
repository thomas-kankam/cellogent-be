<?php

namespace App\Http\Requests\Subscription;

use App\Http\Requests\ApiFormRequest;

class SubscribeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer'],
            'type' => ['nullable', 'in:custom,non_expiry,monthly'],
            'network' => ['nullable', 'string', 'max:50'],
            'method' => ['nullable', 'string', 'in:momo,card,bank,wallet'],
        ];
    }
}
