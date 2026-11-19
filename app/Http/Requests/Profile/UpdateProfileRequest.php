<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\ApiFormRequest;
use App\Support\GhanaPhone;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $user = $this->user();
        $phoneRules = ['sometimes', 'required', 'string', GhanaPhone::rule()];

        if ($user) {
            $phoneRules[] = Rule::unique($user->getTable(), 'phone_number')->ignore($user->getKey());
        }

        return [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'phone_number' => $phoneRules,
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:20'],
            'account_type' => ['sometimes', 'nullable', 'string', 'in:individual,business'],
            'business_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile_image' => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_number.unique' => 'This phone number is already registered to another account.',
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->filled('phone_number')) {
            $this->merge([
                'phone_number' => GhanaPhone::normalize((string) $this->input('phone_number')),
            ]);
        }
    }
}
