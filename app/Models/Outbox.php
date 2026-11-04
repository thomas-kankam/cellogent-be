<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Outbox extends Model
{
    protected $fillable = [
        'inbox_id',
        'client_id',
        'admin_id',
        'request_type',
        'type',
        'to',
        'from',
        'message',
        'contact_group_ids',
        'units_used',
        'previous_units',
        'aggregator',
        'is_global',
        'message_id',
        'status',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'contact_group_ids' => 'array',
            'is_global' => 'boolean',
        ];
    }

    /**
     * Atomically move pending → processing so a re-dispatched job never sends twice.
     */
    public static function claim(int $inboxId): ?self
    {
        $claimed = static::query()
            ->where('inbox_id', $inboxId)
            ->where('status', 'pending')
            ->update(['status' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        return $claimed ? static::query()->where('inbox_id', $inboxId)->first() : null;
    }
}
