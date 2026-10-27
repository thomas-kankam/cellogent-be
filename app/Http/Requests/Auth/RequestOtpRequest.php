<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class RequestOtpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'type' => ['nullable', 'in:login,registration'],
        ];
    }
}
