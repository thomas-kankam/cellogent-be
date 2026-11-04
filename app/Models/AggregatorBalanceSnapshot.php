<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AggregatorBalanceSnapshot extends Model
{
    protected $fillable = [
        'aggregator',
        'credit',
        'intl_credit',
        'client_units_outstanding',
        'headroom',
        'is_low',
        'alert_sent',
    ];

    protected function casts(): array
    {
        return [
            'is_low' => 'boolean',
            'alert_sent' => 'boolean',
        ];
    }
}
