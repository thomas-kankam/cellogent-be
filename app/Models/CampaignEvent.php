<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignEvent extends Model
{
    public const UPDATED_AT = null;

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const CLICK = 'click';

    public const REWARD_PENDING = 'reward_pending';

    public const REWARD_DISBURSED = 'reward_disbursed';

    public const REWARD_FAILED = 'reward_failed';

    public const EVENTS = [
        self::SENT,
        self::DELIVERED,
        self::FAILED,
        self::CLICK,
        self::REWARD_PENDING,
        self::REWARD_DISBURSED,
        self::REWARD_FAILED,
    ];

    protected $fillable = [
        'campaign_id',
        'contact_id',
        'phone',
        'event',
        'occasion_year',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
