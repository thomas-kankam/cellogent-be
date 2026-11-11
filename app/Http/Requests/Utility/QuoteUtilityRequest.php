<?php

namespace App\Http\Requests\Utility;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * One item (fields at the top level) or `items[]` (up to 50). Per service:
 *  - data / mashup: package_id (from the catalog)
 *  - airtime: network (MTN|TELECEL|AT|GLO) + amount
 *  - ecg: amount (+ customer_phone)
 *  - tv: bill_type (DSTV|GOTV|STARTIMES) + amount
 *  - results: checker_type (WASSCE|BECE) + quantity
 * The price itself is always looked up on the server.
 */
class QuoteUtilityRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return $this->itemRules('', 'required_without:items') + [
            'items' => ['nullable', 'array', 'min:1', 'max:'.(int) config('swift.bulk_max', 50)],
            'items.*' => ['array'],
        ] + $this->itemRules('items.*.', 'required');
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
}
