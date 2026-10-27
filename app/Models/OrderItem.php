<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'name',
        'unit_price',
        'order_id',
        'order_code',
        'type',
        'meta_data',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'meta_data' => 'array',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
