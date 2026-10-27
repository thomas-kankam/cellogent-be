<?php

namespace App\Http\Requests\Plan;

use App\Http\Requests\ApiFormRequest;

class StorePlanRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'status' => ['nullable', 'in:active,inactive'],
            'details' => ['nullable', 'array'],
            'details.months' => ['nullable', 'integer', 'min:1'],
            'details.units' => ['nullable', 'integer', 'min:0'],
            'details.price' => ['nullable', 'numeric', 'min:0'],
            'months' => ['nullable', 'integer', 'min:1'],
            'units' => ['nullable', 'integer', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
