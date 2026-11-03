<?php

namespace App\Http\Requests\ApiKey;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\ApiKey\Concerns\NormalizesExpiresAt;

class RegenerateApiKeyRequest extends ApiFormRequest
{
    use NormalizesExpiresAt;

    public function rules(): array
    {
        return [
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after:today'],
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
