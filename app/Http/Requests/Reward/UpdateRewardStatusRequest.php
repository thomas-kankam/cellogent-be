<?php

namespace App\Http\Requests\Reward;

use App\Http\Requests\ApiFormRequest;
use App\Models\Reward;
use Illuminate\Validation\Rule;

class UpdateRewardStatusRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Reward::STATUSES)],
        ];
    }
}
