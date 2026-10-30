<?php

namespace App\Http\Requests\ApiKey;

use App\Http\Requests\ApiFormRequest;

class UpdateApiKeyRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
