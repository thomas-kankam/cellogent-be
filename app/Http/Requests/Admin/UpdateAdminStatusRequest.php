<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Services\AdminService;

class UpdateAdminStatusRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', AdminService::STATUSES)],
        ];
    }
}
