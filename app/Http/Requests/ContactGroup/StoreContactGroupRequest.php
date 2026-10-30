<?php

namespace App\Http\Requests\ContactGroup;

use App\Http\Requests\ApiFormRequest;

class StoreContactGroupRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
