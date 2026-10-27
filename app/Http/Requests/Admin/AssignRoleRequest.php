<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class AssignRoleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'admin_id' => ['required', 'integer', 'exists:admins,id'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ];
    }
}
