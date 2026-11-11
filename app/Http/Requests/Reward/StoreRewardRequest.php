<?php

namespace App\Http\Requests\Reward;

use App\Http\Requests\ApiFormRequest;
use App\Models\Reward;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and update (PUT) share these rules; on update every field is optional.
 */
class StoreRewardRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $update = $this->route('reward') instanceof Reward;
        $required = $update ? 'sometimes' : 'required';
        // Data rewards are picked from the Swift catalog (package_id), which fills in name, size, network and amount.
        $fromCatalog = fn () => $this->input('type') === Reward::DATA && ($this->filled('package_id') || $this->filled('bundle_id'));

        return [
            'name' => [$update ? 'sometimes' : Rule::requiredIf(fn () => ! $fromCatalog()), 'nullable', 'string', 'max:100'],
            'type' => [$required, Rule::in(Reward::TYPES)],
            'package_id' => ['nullable', 'string', 'max:120'],
            'size' => ['nullable', 'string', 'max:30'],
            'amount' => [$update ? 'sometimes' : Rule::requiredIf(fn () => ! $fromCatalog()), 'nullable', 'numeric', 'min:0.01', 'max:100000'],
            'network' => [$update ? 'nullable' : Rule::requiredIf(fn () => $this->input('type') === Reward::AIRTIME), 'nullable', 'string', 'max:30'],
            'units' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'provider_code' => ['nullable', 'string', 'max:100'],
            'bundle_id' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(Reward::STATUSES)],
            'meta' => ['nullable', 'array'],
        ];
    }
}
