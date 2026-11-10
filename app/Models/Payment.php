<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    /** @see Order::TYPES */
    public const PURPOSES = Order::TYPES;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    protected $fillable = [
        'client_id',
        'admin_id',
        'order_id',
        'network',
        'method',
        'reference_id',
        'status',
        'purpose',
        'payment_link',
        'amount',
        'charge',
        'total_amount',
        'currency',
        'gateway',
        'gateway_reference',
        'gateway_charge',
        'msisdn',
        'email',
        'paid_at',
        'failed_at',
        'failure_reason',
        'gateway_response',
        'verify_attempts',
        'next_verify_at',
    ];

    protected $hidden = [
        'gateway_response',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'charge' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'gateway_charge' => 'decimal:2',
            'gateway_response' => 'array',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'next_verify_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
