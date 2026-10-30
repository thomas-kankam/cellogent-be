<?php

namespace App\Http\Requests\Contact;

use App\Http\Requests\ApiFormRequest;

class UploadContactsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx', 'max:102400'],
        ];
    }
}
