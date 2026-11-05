<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class AssignAdminRoleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ];
    }
}
