<?php

namespace App\Http\Requests\Plan;

use App\Http\Requests\ApiFormRequest;
use App\Models\Plan;
use Illuminate\Validation\Rule;

class StorePlanRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $plan = $this->route('plan');
        $planId = $plan instanceof Plan ? $plan->id : null;

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('plans', 'name')->ignore($planId),
            ],
            'status' => ['nullable', 'in:active,inactive'],
            'is_featured' => ['nullable', 'boolean'],
            // Up to 4 packages: e.g. 1 / 2 / 3 months + one non-expiry (months 0 or null).
            'details' => ['required', 'array', 'min:1', 'max:4'],
            'details.*.months' => ['nullable', 'integer', 'min:0', 'max:36'],
            'details.*.units' => ['required', 'integer', 'min:0'],
            'details.*.price' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $details = $this->input('details');

            if (! is_array($details)) {
                return;
            }

            $months = [];

            foreach ($details as $i => $option) {
                if (! is_array($option)) {
                    continue;
                }

                // null / missing → non-expiry (0).
                $m = array_key_exists('months', $option) && $option['months'] !== null && $option['months'] !== ''
                    ? (int) $option['months']
                    : 0;

                if (in_array($m, $months, true)) {
                    $validator->errors()->add(
                        "details.{$i}.months",
                        $m === 0
                            ? 'Only one non-expiry package is allowed on this plan.'
                            : "A {$m}-month package is already on this plan.",
                    );
                }

                $months[] = $m;
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A plan with this name already exists.',
            'details.max' => 'A plan can have at most 4 packages (for example 1, 2, 3 months and one non-expiry).',
            'details.*.months.min' => 'Use 0 (or omit) for a non-expiry package, or 1–36 for a dated pack.',
        ];
    }
}
