<?php

namespace App\Http\Requests\ApiKey;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\ApiKey\Concerns\NormalizesExpiresAt;

class UpdateApiKeyRequest extends ApiFormRequest
{
    use NormalizesExpiresAt;

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }

    public function messages(): array
    {
        return [
            'expires_at.date_format' => 'Use an expiry date like 2026-12-31 or 31/12/2026.',
            'expires_at.after' => 'The expiry date must be after today.',
        ];
    }
}
