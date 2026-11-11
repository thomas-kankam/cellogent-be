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
 * Phone fields are normalized to 233XXXXXXXXX before rules run:
 * - phone_number (profiles, contacts, payments)
 * - to (SMS recipients — string, CSV, or array; never phone_number for SMS)
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

        // Non-scalar values are left as-is so the 'string' rule rejects them with a 422 (not a TypeError 500).
        if ($this->exists('phone_number') && is_scalar($this->input('phone_number'))) {
            $payload['phone_number'] = GhanaPhone::normalize((string) $this->input('phone_number'));
        }

        if ($this->exists('to')) {
            $payload['to'] = $this->normalizeToField($this->input('to'));
        }

        if (is_array($this->input('meta_data')) && array_key_exists('phone', $this->input('meta_data'))) {
            $meta = $this->input('meta_data');
            $meta['phone'] = is_scalar($meta['phone']) ? GhanaPhone::normalize((string) $meta['phone']) : $meta['phone'];
            $payload['meta_data'] = $meta;
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    /**
     * SMS recipient field: one number, CSV/space list, or array → list of normalized strings.
     *
     * @return list<string>|mixed
     */
    protected function normalizeToField(mixed $to): mixed
    {
        if (is_string($to) || is_numeric($to)) {
            $parts = preg_split('/[\s,;]+/', trim((string) $to), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_map(
                fn (string $n) => GhanaPhone::normalize($n) ?? $n,
                $parts,
            ));
        }

        if (! is_array($to)) {
            return $to;
        }

        return array_values(array_map(function ($n) {
            if (! is_scalar($n)) {
                return $n;
            }

            $normalized = GhanaPhone::normalize((string) $n);

            return $normalized ?? (string) $n;
        }, $to));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::make(
            true,
            'Validation failed.',
            ApiCode::VALIDATION,
            ApiReason::VALIDATION_ERROR,
            $validator->errors()->toArray(),
        ));
    }
}
