<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_CLIENT = 'client';

    public const TYPE_QUICK_SMS = 'quick_sms';

    public const TYPE_BULK_SMS = 'bulk_sms';

    public const TYPE_CAMPAIGN_PROMOTION = 'campaign_promotion';

    public const TYPE_CAMPAIGN_OCCASION = 'campaign_occasion';

    public const TYPE_SURVEY = 'survey';

    public const TYPES = [
        self::TYPE_QUICK_SMS,
        self::TYPE_BULK_SMS,
        self::TYPE_CAMPAIGN_PROMOTION,
        self::TYPE_CAMPAIGN_OCCASION,
        self::TYPE_SURVEY,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'scope',
        'client_id',
        'admin_id',
        'type',
        'occasion_type',
        'category',
        'name',
        'description',
        'intro',
        'body',
        'cta',
        'questions',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'cta' => 'array',
            'questions' => 'array',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function isGlobal(): bool
    {
        return $this->scope === self::SCOPE_GLOBAL && $this->client_id === null;
    }

    public function isOwnedByClient(int $clientId): bool
    {
        return $this->scope === self::SCOPE_CLIENT && (int) $this->client_id === $clientId;
    }
}
