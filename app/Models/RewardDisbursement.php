<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RewardDisbursement extends Model
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const STATUSES = [self::PENDING, self::PROCESSING, self::PAID, self::FAILED];

    public const SOURCE_CAMPAIGN = 'campaign';

    public const SOURCE_SURVEY = 'survey';

    protected $fillable = [
        'client_id',
        'source_type',
        'source_id',
        'contact_id',
        'phone',
        'period',
        'reward_type',
        'amount',
        'network',
        'package_id',
        'status',
        'provider',
        'reference',
        'provider_reference',
        'utility_order_id',
        'wallet_hold_id',
        'meta',
        'failure_reason',
        'attempts',
        'next_attempt_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'meta' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function utilityOrder()
    {
        return $this->belongsTo(UtilityOrder::class, 'utility_order_id');
    }

    public function walletHold()
    {
        return $this->belongsTo(WalletHold::class, 'wallet_hold_id');
    }
}
