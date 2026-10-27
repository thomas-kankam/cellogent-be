<?php

namespace App\Http\Requests;

use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\ApiResponse;
use App\Support\GhanaPhone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Every API form request returns the same envelope on validation failure.
 * Any phone_number in the payload is converted to 233XXXXXXXXX before rules run.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->exists('phone_number')) {
            $payload['phone_number'] = GhanaPhone::normalize($this->input('phone_number'));
        }

        if (is_array($this->input('meta_data')) && array_key_exists('phone', $this->input('meta_data'))) {
            $meta = $this->input('meta_data');
            $meta['phone'] = GhanaPhone::normalize($meta['phone'] ?? null);
            $payload['meta_data'] = $meta;
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
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
