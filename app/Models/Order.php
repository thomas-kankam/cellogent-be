<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    /**
     * Order / payment purpose codes shown and filterable in the UI.
     * Checkout only sells a subset today; the rest are reserved for utilities etc.
     */
    public const TYPES = [
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
        'quantity',
        'total_price',
        'status',
        'type',
        'order_code',
    ];

    protected function casts(): array
    {
        return [
            'total_price' => 'decimal:2',
        ];
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public static function typeLabel(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        return match ($type) {
            'sms_units' => 'SMS units',
            'wallet_top_up' => 'Wallet top up',
            'data_bundle' => 'Data bundle',
            'airtime' => 'Airtime',
            'water' => 'Water',
            'ecg' => 'ECG',
            'nedco' => 'NEDCo',
            'gotv' => 'GOtv',
            'dstv' => 'DStv',
            'starttimes' => 'Startimes',
            'kwesetv' => 'Kwese TV',
            'gbctv' => 'GBC TV',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }
}
