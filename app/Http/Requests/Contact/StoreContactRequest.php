<?php

namespace App\Http\Requests\Contact;

use App\Http\Requests\ApiFormRequest;
use App\Support\GhanaPhone;

class StoreContactRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'gender' => ['nullable', 'string', 'max:20'],
            'phone_number' => ['required', 'string', GhanaPhone::rule()],
            'date_of_birth' => ['nullable', 'date'],
            'region' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'gps_address' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'dnd_status' => ['nullable', 'in:active,inactive'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_number.required' => 'A phone number is required. SMS is sent to this number.',
            'phone_number.regex' => 'Use a Ghana mobile number, for example 0244000000 or 233244000000.',
        ];
    }
}
