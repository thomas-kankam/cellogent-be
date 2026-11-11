<?php

namespace App\Http\Requests\Campaign;

use App\Http\Requests\ApiFormRequest;
use App\Support\GhanaPhone;

/**
 * Public click tracking (campaign link or survey link). The phone is optional.
 */
class TrackClickRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'phone_number' => ['nullable', 'string', GhanaPhone::rule()],
        ];
    }
}
