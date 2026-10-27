<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\ApiFormRequest;
use App\Models\Payment;

class StoreOrderRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:'.implode(',', Payment::PURPOSES)],
            'name' => ['nullable', 'string', 'max:150'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'network' => ['nullable', 'string', 'max:50'],
            'method' => ['nullable', 'string', 'in:momo,card,bank,wallet'],
            'meta_data' => ['nullable', 'array'],
        ];
    }
}
