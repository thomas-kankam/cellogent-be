<?php

namespace App\Http\Requests\Sms;

use App\Http\Requests\ApiFormRequest;

/**
 * `to` accepts one number, a comma/space separated string, or an array of numbers.
 */
class SendQuickSmsRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $to = $this->input('to');

        if (is_string($to) || is_numeric($to)) {
            $to = preg_split('/[\s,;]+/', trim((string) $to), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (is_array($to)) {
            $this->merge(['to' => array_values(array_map(fn ($n) => is_scalar($n) ? (string) $n : $n, $to))]);
        }
    }

    public function rules(): array
    {
        $max = (int) config('sms.quick.max_recipients', 100);

        return [
            'from' => ['required', 'string', 'min:3', 'max:11'],
            'to' => ['required', 'array', 'min:1', "max:{$max}"],
            'to.*' => ['required', 'string', 'max:20'],
            'message' => ['required', 'string', 'max:'.(int) config('sms.message_max_length', 1600)],
        ];
    }

    public function messages(): array
    {
        return [
            'to.max' => 'Quick SMS supports up to :max numbers. Use bulk SMS with a contact group for more.',
        ];
    }
}
