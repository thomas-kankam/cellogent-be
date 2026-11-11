<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reward extends Model
{
    public const INSTOCK = 'instock';

    public const OUT_OF_STOCK = 'out_of_stock';

    public const STATUSES = [self::INSTOCK, self::OUT_OF_STOCK];

    public const MONEY = 'money';

    public const AIRTIME = 'airtime';

    public const DATA = 'data';

    public const TYPES = [self::MONEY, self::AIRTIME, self::DATA];

    protected $fillable = [
        'name',
        'type',
        'size',
        'amount',
        'network',
        'units',
        'currency',
        'provider_code',
        'bundle_id',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'units' => 'integer',
            'meta' => 'array',
        ];
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class, 'reward_id');
    }

    public function surveys()
    {
        return $this->hasMany(Survey::class, 'reward_id');
    }
}
