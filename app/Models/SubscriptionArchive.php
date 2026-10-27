<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionArchive extends Model
{
    protected $table = 'subscription_archieves';

    protected $fillable = [
        'client_id',
        'plan_id',
        'plan_name',
        'plan_price',
        'plan_type',
        'duration_in_months',
        'starts_at',
        'ends_at',
        'payment_status',
        'payment_id',
        'payment_reference',
    ];

    protected function casts(): array
    {
        return [
            'plan_price' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
