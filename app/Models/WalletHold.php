<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletHold extends Model
{
    public const ACTIVE = 'active';

    public const RELEASED = 'released';

    public const CONSUMED = 'consumed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'client_id',
        'wallet_id',
        'holdable_type',
        'holdable_id',
        'amount',
        'remaining',
        'currency',
        'status',
        'purpose',
        'payment_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'remaining' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function holdable()
    {
        return $this->morphTo();
    }
}
