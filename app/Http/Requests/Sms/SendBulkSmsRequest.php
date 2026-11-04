<?php

namespace App\Http\Requests\Sms;

use App\Http\Requests\ApiFormRequest;

class SendBulkSmsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $max = (int) config('sms.bulk.max_groups', 20);

        return [
            'from' => ['required', 'string', 'min:3', 'max:11'],
            'contact_group_ids' => ['required', 'array', 'min:1', "max:{$max}"],
            'contact_group_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'message' => ['required', 'string', 'max:'.(int) config('sms.message_max_length', 1600)],
        ];
    }
}
