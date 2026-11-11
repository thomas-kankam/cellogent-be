<?php

namespace App\Http\Requests\Survey;

use App\Http\Requests\ApiFormRequest;
use App\Support\GhanaPhone;

class SubmitSurveyRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', GhanaPhone::rule()],
            'answers' => ['required', 'array', 'min:1', 'max:100'],
        ];
    }
}
