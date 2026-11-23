<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inbox extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_QUICK_SMS = 'quick_sms';

    public const SOURCE_BULK_SMS = 'bulk_sms';

    public const SOURCE_CAMPAIGN_PROMOTION = 'campaign_promotion';

    public const SOURCE_CAMPAIGN_OCCASION = 'campaign_occasion';

    public const SOURCE_SURVEY = 'survey';

    public const SOURCES = [
        self::SOURCE_QUICK_SMS,
        self::SOURCE_BULK_SMS,
        self::SOURCE_CAMPAIGN_PROMOTION,
        self::SOURCE_CAMPAIGN_OCCASION,
        self::SOURCE_SURVEY,
    ];

    protected $fillable = [
        'client_id',
        'admin_id',
        'parent_id',
        'mode',
        'request_type',
        'type',
        'source',
        'source_id',
        'to',
        'from',
        'message',
        'parts',
        'contact_group_ids',
        'recipients_count',
        'units_used',
        'previous_units',
        'units_refunded',
        'submitted_count',
        'delivered_count',
        'failed_count',
        'batches_total',
        'batches_done',
        'aggregator',
        'is_global',
        'message_id',
        'status',
        'scheduled_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'contact_group_ids' => 'array',
            'is_global' => 'boolean',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return $this->to ? explode(',', $this->to) : [];
    }

    public function isQuick(): bool
    {
        return $this->type === 'quick';
    }

    public function resolvedSource(): string
    {
        if (is_string($this->source) && $this->source !== '') {
            return $this->source;
        }

        return $this->isQuick() ? self::SOURCE_QUICK_SMS : self::SOURCE_BULK_SMS;
    }
}
