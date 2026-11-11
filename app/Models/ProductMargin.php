<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductMargin extends Model
{
    public const PRODUCT_TYPES = [
        'data_bundle',
        'airtime',
        'ecg',
        'tv',
        'results',
        'sms_units',
        'wallet_top_up',
        'mashup',
    ];

    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    public const MARKUP_TYPES = [self::PERCENT, self::FIXED];

    protected $fillable = [
        'product_type',
        'network',
        'package_id',
        'markup_type',
        'markup_value',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'markup_value' => 'decimal:4',
            'active' => 'boolean',
        ];
    }
}
