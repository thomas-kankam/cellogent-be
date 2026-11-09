<?php

namespace App\Http\Requests\Sms;

use App\Http\Requests\ApiFormRequest;
use App\Support\GhanaPhone;

/**
 * Quick SMS for dashboard and public API.
 * Recipient field is always `to` (not phone_number): one number, CSV, or array.
 */
class SendQuickSmsRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        // Canonical SMS field is `to`. Map mistaken phone_number payloads so both app + API work.
        if (! $this->filled('to') && $this->filled('phone_number')) {
            $this->merge(['to' => $this->input('phone_number')]);
        }

        parent::prepareForValidation();
    }

    public function rules(): array
    {
        $max = (int) config('sms.quick.max_recipients', 100);

        return [
            'from' => ['required', 'string', 'min:3', 'max:11'],
            'to' => ['required', 'array', 'min:1', "max:{$max}"],
            'to.*' => ['required', 'string', GhanaPhone::rule()],
            'message' => ['required', 'string', 'max:'.(int) config('sms.message_max_length', 1600)],
        ];
    }

    public function messages(): array
    {
        return [
            'to.required' => 'Provide recipients in the "to" field (one number, a list, or an array).',
            'to.max' => 'Quick SMS supports up to :max numbers. Use bulk SMS with a contact group for more.',
            'to.*.regex' => 'Each recipient in "to" must be a Ghana mobile number, for example 0244000000 or 233244000000.',
        ];
    }
}
