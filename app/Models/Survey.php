<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Survey extends Model
{
    use SoftDeletes;

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

    /** Statuses in which the survey content can still be edited. */
    public const EDITABLE = [self::STATUS_DRAFT, self::STATUS_FUNDED, self::STATUS_PAUSED];

    public const QUESTION_TYPES = ['text', 'long_text', 'single_choice', 'multiple_choice', 'rating', 'yes_no', 'number'];

    protected $fillable = [
        'client_id',
        'name',
        'intro',
        'body',
        'contact_group_ids',
        'sender',
        'send_at',
        'public_token',
        'reward_enabled',
        'reward',
        'reward_id',
        'funding_method',
        'wallet_hold_id',
        'payment_id',
        'reserved_amount',
        'estimated_cost',
        'eligible_recipients',
        'not_eligible',
        'analytics',
        'status',
        'activated_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'contact_group_ids' => 'array',
            'send_at' => 'datetime',
            'reward_enabled' => 'boolean',
            'reward' => 'array',
            'reserved_amount' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
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

    public function questions()
    {
        return $this->hasMany(SurveyQuestion::class, 'survey_id')->orderBy('position');
    }

    public function responses()
    {
        return $this->hasMany(SurveyResponse::class, 'survey_id');
    }

    public function walletHolds()
    {
        return $this->morphMany(WalletHold::class, 'holdable');
    }
}
