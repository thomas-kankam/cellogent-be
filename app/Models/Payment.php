<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    public const PURPOSES = [
        'sms_units',
        'wallet_top_up',
        'data_bundle',
        'airtime',
        'water',
        'ecg',
        'nedco',
        'gotv',
        'dstv',
        'starttimes',
        'kwesetv',
        'gbctv',
    ];

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
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
