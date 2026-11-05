<?php

namespace App\Http\Requests\Concerns;

use App\Support\GhanaPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Individual vs business rules shared by signup, admin onboard, and admin update.
 *
 * individual → first_name, last_name, date_of_birth required; business fields cleared.
 * business   → business_name, region, city, address, profile_image (logo) required; person fields optional.
 *
 * @mixin FormRequest
 */
trait ValidatesClientAccount
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function clientAccountRules(bool $creating = true): array
    {
        return [
            'account_type' => [$creating ? 'required' : 'sometimes', 'in:individual,business'],
            'email' => [$creating ? 'required' : 'sometimes', 'email', 'max:255'],
            'phone_number' => [$creating ? 'required' : 'sometimes', 'string', GhanaPhone::rule()],
            'gender' => ['nullable', 'string', 'max:20'],

            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],

            'business_name' => ['nullable', 'string', 'max:150'],
            'region' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],

            'profile_image' => [
                $creating ? 'nullable' : 'sometimes',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function clientAccountMessages(): array
    {
        return [
            'account_type.required' => 'Choose individual or business.',
            'account_type.in' => 'Account type must be individual or business.',
            'first_name.required' => 'First name is required for individual accounts.',
            'last_name.required' => 'Last name is required for individual accounts.',
            'date_of_birth.required' => 'Date of birth is required for individual accounts.',
            'business_name.required' => 'Business name is required for business accounts.',
            'region.required' => 'Region is required for business accounts.',
            'city.required' => 'City is required for business accounts.',
            'address.required' => 'Address is required for business accounts.',
            'profile_image.required' => 'A business logo (profile image) is required for business accounts.',
        ];
    }

    /**
     * Enforce the fields that belong to the chosen account type.
     */
    protected function validateClientAccountCompleteness(Validator $validator, bool $creating = true): void
    {
        $validator->after(function (Validator $validator) use ($creating) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $client = (! $creating && method_exists($this, 'route')) ? $this->route('client') : null;
            $type = $this->input('account_type', $client?->account_type);

            if ($type === 'individual') {
                foreach (['first_name', 'last_name', 'date_of_birth'] as $field) {
                    $value = $this->exists($field) ? $this->input($field) : $client?->{$field};
                    if (blank($value)) {
                        $validator->errors()->add($field, $this->clientAccountMessages()["{$field}.required"]);
                    }
                }
            }

            if ($type === 'business') {
                foreach (['business_name', 'region', 'city', 'address'] as $field) {
                    $value = $this->exists($field) ? $this->input($field) : $client?->{$field};
                    if (blank($value)) {
                        $validator->errors()->add($field, $this->clientAccountMessages()["{$field}.required"]);
                    }
                }

                $hasImage = $this->hasFile('profile_image') || filled($client?->profile_image);
                if (! $hasImage) {
                    $validator->errors()->add('profile_image', $this->clientAccountMessages()['profile_image.required']);
                }
            }
        });
    }

    /**
     * Drop fields that do not belong to the chosen account type so they are not stored.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalizeClientAccountPayload(array $input): array
    {
        if (($input['account_type'] ?? null) === 'individual') {
            $input['business_name'] = null;
            $input['region'] = null;
            $input['city'] = null;
            $input['address'] = null;
        }

        return $input;
    }
}
