<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTopUp extends Model
{
    protected $fillable = [
        'wallet_id',
        'client_id',
        'amount',
        'charge',
        'amount_paid',
        'previous_balance',
        'balance_after',
        'payment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'charge' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'previous_balance' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
