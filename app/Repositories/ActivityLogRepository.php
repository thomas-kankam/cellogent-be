<?php

namespace App\Repositories;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ActivityLogRepository
{
    public function create(array $attributes): ActivityLog
    {
        return ActivityLog::create($attributes);
    }

    /**
     * Filterable list. Used by both admin (all logs) and "logs for this client".
     */
    public function query(array $filters = []): Builder
    {
        return ActivityLog::query()
            ->with(['transmitter', 'receiver'])
            ->when($filters['module'] ?? null, fn (Builder $q, string $module) => $q->where('module', $module))
            ->when($filters['action'] ?? null, fn (Builder $q, string $action) => $q->where('action', $action))
            ->when($filters['actor'] ?? null, fn (Builder $q, string $actor) => $q->where('actor', $actor))
            ->when($filters['receiver'] ?? null, function (Builder $q) use ($filters) {
                /** @var Model $receiver */
                $receiver = $filters['receiver'];
                $q->where('receiver_type', $receiver->getMorphClass())
                    ->where('receiver_id', $receiver->getKey());
            })
            ->latest('id');
    }
}
