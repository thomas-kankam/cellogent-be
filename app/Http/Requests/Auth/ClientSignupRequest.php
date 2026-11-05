<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ValidatesClientAccount;
use Illuminate\Validation\Validator;

class ClientSignupRequest extends ApiFormRequest
{
    use ValidatesClientAccount;

    public function rules(): array
    {
        // Wallet and trial are created later, after POST /client/verify_otp with type=registration.
        return $this->clientAccountRules(creating: true);
    }

    public function messages(): array
    {
        return $this->clientAccountMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateClientAccountCompleteness($validator, creating: true);
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->filled('account_type')) {
            $this->merge([
                'account_type' => strtolower(trim((string) $this->input('account_type'))),
            ]);
        }
    }
}
