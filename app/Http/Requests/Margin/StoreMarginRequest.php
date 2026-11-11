<?php

namespace App\Http\Requests\Margin;

use App\Http\Requests\ApiFormRequest;
use App\Models\ProductMargin;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and update (PUT) share these rules; on update every field is optional.
 * network / package_id narrow a margin to one network or one bundle (the most specific rule wins).
 */
class StoreMarginRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $update = $this->route('margin') instanceof ProductMargin;
        $required = $update ? 'sometimes' : 'required';

        return [
            'product_type' => [$required, Rule::in(ProductMargin::PRODUCT_TYPES)],
            'network' => ['nullable', 'string', 'max:60'],
            'package_id' => ['nullable', 'string', 'max:120'],
            'markup_type' => [$required, Rule::in(ProductMargin::MARKUP_TYPES)],
            'markup_value' => [$required, 'numeric', 'min:0', 'max:100000'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
