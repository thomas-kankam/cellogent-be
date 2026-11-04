<?php

namespace App\Http\Requests\Sms;

use App\Http\Requests\ApiFormRequest;

class ReblastSmsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:'.(int) config('sms.message_max_length', 1600)],
        ];
    }
}
