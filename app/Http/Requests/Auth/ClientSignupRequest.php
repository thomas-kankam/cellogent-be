<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class ClientSignupRequest extends ApiFormRequest
{
    /**
     * referral_code is generated on the server after the account is created.
     */
    protected function prepareForValidation(): void
    {
        $this->request->remove('referral_code');
    }

    public function rules(): array
    {
        $isBusiness = $this->input('account_type') === 'business';
        $isIndividual = $this->input('account_type') === 'individual';

        $person = $isIndividual ? 'required' : 'nullable';
        $business = $isBusiness ? 'required' : 'nullable';

        return [
            'account_type' => ['required', 'in:individual,business'],
            'email' => ['required', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', 'string', 'max:20'],

            // Individual: identity is required. Business: optional.
            'first_name' => [$person, 'string', 'max:100'],
            'last_name' => [$person, 'string', 'max:100'],
            'date_of_birth' => [$person, 'date'],

            // Business: company + location + logo are required. Individual: optional.
            'business_name' => [$business, 'string', 'max:150'],
            'region' => [$business, 'string', 'max:100'],
            'city' => [$business, 'string', 'max:100'],
            'address' => [$business, 'string', 'max:255'],
            'profile_image' => [$business, 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
