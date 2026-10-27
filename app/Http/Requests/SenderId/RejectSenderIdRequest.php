<?php

namespace App\Http\Requests\SenderId;

use App\Http\Requests\ApiFormRequest;

class RejectSenderIdRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:255'],
        ];
    }
}
