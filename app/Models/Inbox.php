<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inbox extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'client_id',
        'admin_id',
        'parent_id',
        'mode',
        'request_type',
        'type',
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
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'contact_group_ids' => 'array',
            'is_global' => 'boolean',
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
}
