<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ValidatesClientAccount;
use Illuminate\Validation\Validator;

class UpdateClientRequest extends ApiFormRequest
{
    use ValidatesClientAccount;

    public function rules(): array
    {
        return $this->clientAccountRules(creating: false);
    }

    public function messages(): array
    {
        return $this->clientAccountMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateClientAccountCompleteness($validator, creating: false);
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
