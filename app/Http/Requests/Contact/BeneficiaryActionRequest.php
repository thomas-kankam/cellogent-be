<?php

namespace App\Http\Requests\Contact;

use App\Http\Requests\ApiFormRequest;

/**
 * Optional list of numbers to limit a beneficiary check / submit to; omit it to use the whole group.
 */
class BeneficiaryActionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'phones' => ['nullable', 'array', 'min:1', 'max:5000'],
            'phones.*' => ['required', 'string', 'max:20'],
        ];
    }
}
