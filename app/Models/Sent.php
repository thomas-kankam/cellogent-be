<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sent extends Model
{
    public const QUEUED = 'queued';

    public const SUBMITTED = 'submitted';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    protected $fillable = [
        'inbox_id',
        'client_id',
        'admin_id',
        'request_type',
        'type',
        'to',
        'from',
        'message',
        'units_used',
        'aggregator',
        'is_global',
        'message_id',
        'sequence_no',
        'job_id',
        'status',
        'dlr_status',
        'error',
        'units_refunded',
        'retry_inbox_id',
        'dlr_attempts',
        'next_dlr_at',
        'submitted_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'is_global' => 'boolean',
            'units_refunded' => 'boolean',
            'next_dlr_at' => 'datetime',
            'submitted_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
