<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AggregatorRequest extends Model
{
    protected $fillable = [
        'aggregator',
        'endpoint',
        'inbox_id',
        'message_count',
        'http_status',
        'ok',
        'status_message',
        'transaction_id',
        'sequence_no',
        'duration_ms',
        'response',
    ];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'response' => 'array',
        ];
    }
}
