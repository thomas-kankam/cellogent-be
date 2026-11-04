<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsUnitLedger extends Model
{
    protected $fillable = [
        'client_id',
        'inbox_id',
        'type',
        'units',
        'balance_before',
        'balance_after',
        'description',
    ];
}
