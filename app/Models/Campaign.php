<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use SoftDeletes;

    public const TYPE_OCCASION = 'occasion';

    public const TYPE_PROMOTION = 'promotion';

    public const TYPES = [self::TYPE_OCCASION, self::TYPE_PROMOTION];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FUNDED = 'funded';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_FUNDED,
        self::STATUS_SCHEDULED,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses in which the campaign content can still be edited. */
    public const EDITABLE = [self::STATUS_DRAFT, self::STATUS_FUNDED, self::STATUS_PAUSED];

    protected $fillable = [
        'client_id',
        'name',
        'type',
        'status',
        'occasion_type',
        'custom_date',
        'trigger_offset',
        'custom_offset_days',
        'send_time',
        'repeat_yearly',
        'contact_group_ids',
        'sender',
        'body',
        'send_at',
        'cta',
        'reward_enabled',
        'reward',
        'reward_id',
        'funding_method',
        'wallet_hold_id',
        'payment_id',
        'reserved_amount',
        'estimated_cost',
        'estimated_cost_units',
        'eligible_recipients',
        'not_eligible',
        'analytics',
        'inbox_id',
        'activated_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'custom_date' => 'date',
            'repeat_yearly' => 'boolean',
            'contact_group_ids' => 'array',
            'send_at' => 'datetime',
            'cta' => 'array',
            'reward_enabled' => 'boolean',
            'reward' => 'array',
            'reserved_amount' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
            'estimated_cost_units' => 'integer',
            'eligible_recipients' => 'integer',
            'not_eligible' => 'integer',
            'analytics' => 'array',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function reward()
    {
        return $this->belongsTo(Reward::class, 'reward_id');
    }

    public function walletHold()
    {
        return $this->belongsTo(WalletHold::class, 'wallet_hold_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function inbox()
    {
        return $this->belongsTo(Inbox::class, 'inbox_id');
    }

    public function events()
    {
        return $this->hasMany(CampaignEvent::class, 'campaign_id');
    }

    public function walletHolds()
    {
        return $this->morphMany(WalletHold::class, 'holdable');
    }
}
