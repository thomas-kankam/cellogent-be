<?php

namespace App\Http\Requests\SenderId;

use App\Http\Requests\ApiFormRequest;

class StoreSenderIdBlacklistRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'match_type' => ['nullable', 'in:exact,contains'],
            'source' => ['nullable', 'in:aggregator,telco,internal'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
