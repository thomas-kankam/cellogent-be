<?php

namespace App\Repositories;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
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
     *
     * Filters: module, action, actor (admin|client|system), search, from, to,
     * admin_id / client_id (who performed it), receiver (Client model for client timeline).
     */
    public function query(array $filters = []): Builder
    {
        return ActivityLog::query()
            ->with(['transmitter', 'receiver'])
            ->when($filters['module'] ?? null, fn (Builder $q, string $module) => $q->where('module', $module))
            ->when($filters['action'] ?? null, fn (Builder $q, string $action) => $q->where('action', $action))
            ->when($filters['actor'] ?? null, fn (Builder $q, string $actor) => $q->where('actor', $actor))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.trim($search).'%';
                $q->where('title', 'like', $like);
            })
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<=', $to))
            ->when($filters['admin_id'] ?? null, function (Builder $q, int $adminId) {
                $q->where('transmitter_type', (new Admin)->getMorphClass())
                    ->where('transmitter_id', $adminId);
            })
            ->when($filters['client_id'] ?? null, function (Builder $q, int $clientId) {
                $q->where('transmitter_type', (new Client)->getMorphClass())
                    ->where('transmitter_id', $clientId);
            })
            ->when($filters['receiver'] ?? null, function (Builder $q) use ($filters) {
                /** @var Model $receiver */
                $receiver = $filters['receiver'];
                $q->where('receiver_type', $receiver->getMorphClass())
                    ->where('receiver_id', $receiver->getKey());
            })
            ->latest('id');
    }
}
