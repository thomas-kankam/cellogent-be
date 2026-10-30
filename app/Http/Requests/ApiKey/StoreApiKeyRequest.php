<?php

namespace App\Http\Requests\ApiKey;

use App\Http\Requests\ApiFormRequest;

class StoreApiKeyRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
