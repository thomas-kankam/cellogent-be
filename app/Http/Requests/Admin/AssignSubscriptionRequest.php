<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class AssignSubscriptionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer'],
            // 0 = non-expiry package; type defaults from months when omitted.
            'months' => ['required', 'integer', 'min:0', 'max:36'],
            'type' => ['nullable', 'in:custom,non_expiry,monthly'],
        ];
    }

    public function messages(): array
    {
        return [
            'months.min' => 'Use 0 for a non-expiry package, or 1–36 for a dated pack.',
        ];
    }
}
