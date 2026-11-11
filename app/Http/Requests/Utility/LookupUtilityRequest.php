<?php

namespace App\Http\Requests\Utility;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Check an ECG meter / account or TV smartcard and get the customer's name before paying.
 */
class LookupUtilityRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'service' => ['required', 'string', Rule::in(['ecg', 'tv'])],
            'beneficiary' => ['required', 'string', 'max:30'],
            'bill_type' => ['required_if:service,tv', 'nullable', 'string', 'max:30'],
        ];
    }
}
