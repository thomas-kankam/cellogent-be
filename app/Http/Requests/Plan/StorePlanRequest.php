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
            'details' => ['required', 'array', 'min:1'],
            'details.*.months' => ['required', 'integer', 'min:1'],
            'details.*.units' => ['required', 'integer', 'min:0'],
            'details.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
