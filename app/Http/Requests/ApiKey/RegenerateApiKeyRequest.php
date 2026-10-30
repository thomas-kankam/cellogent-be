<?php

namespace App\Http\Requests\ApiKey;

use App\Http\Requests\ApiFormRequest;

class RegenerateApiKeyRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
