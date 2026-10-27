<?php

namespace App\Http\Requests\SenderId;

use App\Http\Requests\ApiFormRequest;

class StoreSenderIdRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:11', 'regex:/^[A-Za-z0-9 ]+$/'],
        ];
    }
}
