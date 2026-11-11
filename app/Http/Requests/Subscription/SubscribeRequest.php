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
            // 0 = non-expiry package on the selected plan.
            'months' => ['required', 'integer', 'min:0', 'max:36'],
            'method' => ['required', 'string', 'in:momo,card,wallet'],
            'phone_number' => ['nullable', 'string', GhanaPhone::rule()],
            'network' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'months.min' => 'Use 0 for a non-expiry package, or 1–36 for a dated pack.',
        ];
    }
}
