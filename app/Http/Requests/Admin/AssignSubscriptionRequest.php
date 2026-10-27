<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class AssignSubscriptionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer'],
            'type' => ['nullable', 'in:custom,non_expiry,monthly'],
        ];
    }
}
