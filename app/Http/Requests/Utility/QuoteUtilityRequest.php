<?php

namespace App\Http\Requests\Utility;

use App\Http\Requests\ApiFormRequest;
use App\Support\GhanaPhone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One item (fields at the top level), `items[]` (≤50 mixed lines), `beneficiaries`
 * (CSV / array of phones for the same package), or `contact_group_ids`.
 *
 * Per service:
 *  - data / mashup: package_id (from the catalog)
 *  - airtime: network (MTN|TELECEL|AT|GLO) + amount
 *  - ecg: amount (+ customer_phone)
 *  - tv: bill_type (DSTV|GOTV|STARTIMES) + amount
 *  - results: checker_type (WASSCE|BECE) + quantity
 *
 * Prices are always looked up on the server. Swift data/airtime is 1 phone per API
 * call — our bulk creates N local orders and queues them (rate-limited).
 */
class QuoteUtilityRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->exists('beneficiaries')) {
            $this->merge(['beneficiaries' => $this->normalizeBeneficiaries($this->input('beneficiaries'))]);
        }
    }

    public function rules(): array
    {
        $bulkMax = (int) config('swift.bulk_max', 50);

        return $this->itemRules('', 'required_without_all:items,contact_group_ids,beneficiaries') + [
            'items' => ['nullable', 'array', 'min:1', 'max:'.$bulkMax],
            'items.*' => ['array'],
            // Optional on quote; required on purchase when not using items/groups/beneficiaries.
            'beneficiary' => ['nullable', 'string', 'max:30'],
            // Same package to many phones (CSV string or array). Caps at Swift-friendly batch size.
            'beneficiaries' => ['nullable', 'array', 'min:1', 'max:'.$bulkMax],
            'beneficiaries.*' => ['required', 'string', 'max:20'],
            // Expand a group into recipients (YELLO/MTN SME data only sends to verified beneficiaries).
            'contact_group_ids' => ['nullable', 'array', 'min:1', 'max:50'],
            'contact_group_ids.*' => ['integer', 'min:1'],
        ] + $this->itemRules('items.*.', 'required');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $modes = array_filter([
                'items' => $this->filled('items'),
                'contact_group_ids' => $this->filled('contact_group_ids'),
                'beneficiaries' => $this->filled('beneficiaries'),
                'beneficiary' => $this->filled('beneficiary'),
            ]);

            // Single recipient modes may coexist with nothing else; mixing recipient shapes is rejected.
            if (count($modes) > 1) {
                $validator->errors()->add(
                    'beneficiaries',
                    'Use only one of: beneficiary, beneficiaries, items, or contact_group_ids.'
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function itemRules(string $prefix, string $serviceRule): array
    {
        return [
            $prefix.'service' => [$serviceRule, 'nullable', 'string', Rule::in(array_keys((array) config('swift.services', [])))],
            $prefix.'package_id' => ['nullable', 'string', 'max:120'],
            $prefix.'network' => ['nullable', 'string', 'max:50'],
            $prefix.'amount' => ['nullable', 'numeric', 'min:0.01'],
            $prefix.'bill_type' => ['nullable', 'string', 'max:30'],
            $prefix.'checker_type' => ['nullable', 'string', 'max:30'],
            $prefix.'quantity' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('swift.results_max_quantity', 100)],
        ];
    }

    /**
     * Accept array, CSV, semicolon, or newline-separated phone lists.
     *
     * @return list<string>
     */
    protected function normalizeBeneficiaries(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_string($raw)) {
            $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            return [];
        }

        $out = [];

        foreach ($parts as $part) {
            if (! is_scalar($part)) {
                continue;
            }

            $raw = trim((string) $part);

            if ($raw === '') {
                continue;
            }

            // Prefer 233… so purchase GhanaPhone::rule() passes; keep raw if unparseable for a clear 422.
            $phone = GhanaPhone::normalize($raw) ?? $raw;
            $out[$phone] = $phone;
        }

        return array_values($out);
    }
}
