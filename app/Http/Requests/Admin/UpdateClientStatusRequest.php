<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Services\ClientService;

class UpdateClientStatusRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', ClientService::STATUSES)],
        ];
    }
}
