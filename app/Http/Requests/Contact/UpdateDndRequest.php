<?php

namespace App\Http\Requests\Contact;

use App\Http\Requests\ApiFormRequest;

class UpdateDndRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'dnd_status' => ['required', 'in:active,inactive'],
        ];
    }
}
