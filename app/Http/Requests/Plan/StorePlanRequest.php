<?php

namespace App\Http\Requests\Plan;

use App\Http\Requests\ApiFormRequest;
use App\Models\Plan;
use Illuminate\Validation\Rule;

class StorePlanRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $plan = $this->route('plan');
        $planId = $plan instanceof Plan ? $plan->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('plans', 'name')->ignore($planId),
            ],
            'status' => ['nullable', 'in:active,inactive'],
            'is_featured' => ['nullable', 'boolean'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.months' => ['required', 'integer', 'min:1'],
            'details.*.units' => ['required', 'integer', 'min:0'],
            'details.*.price' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A plan with this name already exists.',
        ];
    }
}
