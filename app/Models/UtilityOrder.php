<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UtilityOrder extends Model
{
    protected $fillable = [
        'client_id',
        'payment_id',
        'order_id',
        'service_type',
        'product_type',
        'provider_code',
        'network',
        'beneficiary',
        'size_gb',
        'package_code',
        'face_amount',
        'amount',
        'cost_amount',
        'sell_amount',
        'profit_amount',
        'currency',
        'reference',
        'batch_id',
        'provider_reference',
        'status',
        'funding_method',
        'provider_payload',
        'provider_response',
        'utility_meta',
        'failure_reason',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'size_gb' => 'decimal:2',
            'face_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'cost_amount' => 'decimal:2',
            'sell_amount' => 'decimal:2',
            'profit_amount' => 'decimal:2',
            'provider_payload' => 'array',
            'provider_response' => 'array',
            'utility_meta' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
