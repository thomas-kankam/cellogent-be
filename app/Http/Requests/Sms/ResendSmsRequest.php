<?php

namespace App\Http\Requests\Sms;

use App\Http\Requests\ApiFormRequest;

class ResendSmsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.(int) config('sms.message_max_length', 1600)],
        ];
    }
}
