<?php

namespace App\Http\Requests;

use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Every API form request returns the same envelope on validation failure.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::make(
            true,
            'Validation failed.',
            ApiCode::VALIDATION,
            ApiReason::VALIDATION_ERROR,
            ['errors' => $validator->errors()->toArray()],
        ));
    }
}
