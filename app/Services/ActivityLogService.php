<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Actor;
use App\Models\Admin;
use App\Models\Client;
use App\Models\SenderId;
use App\Repositories\ActivityLogRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Write and read activity logs.
 *
 * Example: client requests a sender ID, later an admin approves it.
 * Both events are rows here so the admin client-timeline can show:
 *   requested by client X at T1, approved by admin Y at T2.
 */
class ActivityLogService
{
    public function __construct(protected ActivityLogRepository $logs)
    {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $title,
        string $module,
        string $action,
        string $actor,
        ?Model $transmitter = null,
        ?Model $receiver = null,
        array $metadata = [],
    ): ActivityLog {
        return $this->logs->create([
            'title' => $title,
            'module' => $module,
            'action' => $action,
            'actor' => $actor,
            'transmitter_type' => $transmitter?->getMorphClass(),
            'transmitter_id' => $transmitter?->getKey(),
            'receiver_type' => $receiver?->getMorphClass(),
            'receiver_id' => $receiver?->getKey(),
            'metadata' => $metadata ?: null,
        ]);
    }

    public function forActorSelf(Actor $actor, string $title, string $module, string $action, array $metadata = []): ActivityLog
    {
        return $this->record(
            $title,
            $module,
            $action,
            $actor->guardName(),
            $actor,
            $actor,
            $metadata,
        );
    }

    /**
     * Call these from the sender-ID feature when you build it.
     * The admin client timeline then shows request + approval/rejection as two rows.
     */
    public function senderIdRequested(Client $client, SenderId $senderId): ActivityLog
    {
        return $this->record(
            'Sender ID requested',
            'sender_id',
            'requested',
            'client',
            $client,
            $client,
            ['sender_id' => $senderId->name, 'status' => $senderId->status],
        );
    }

    public function senderIdApproved(Admin $admin, Client $client, SenderId $senderId): ActivityLog
    {
        return $this->record(
            'Sender ID approved',
            'sender_id',
            'approved',
            'admin',
            $admin,
            $client,
            [
                'sender_id' => $senderId->name,
                'approved_at' => optional($senderId->approved_at)->toDateTimeString(),
            ],
        );
    }

    public function senderIdRejected(Admin $admin, Client $client, SenderId $senderId): ActivityLog
    {
        return $this->record(
            'Sender ID rejected',
            'sender_id',
            'rejected',
            'admin',
            $admin,
            $client,
            [
                'sender_id' => $senderId->name,
                'reason' => $senderId->rejection_reason,
                'rejected_at' => optional($senderId->rejected_at)->toDateTimeString(),
            ],
        );
    }

    public function query(array $filters = [])
    {
        return $this->logs->query($filters);
    }

    /**
     * Shape used by list endpoints.
     *
     * @return array<string, mixed>
     */
    public function present(ActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'title' => $log->title,
            'module' => $log->module,
            'action' => $log->action,
            'actor' => $log->actor,
            'transmitter' => $this->presentParty($log->transmitter),
            'receiver' => $this->presentParty($log->receiver),
            'metadata' => $log->metadata ?? [],
            'created_at' => optional($log->created_at)->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function presentParty(?Model $party): ?array
    {
        if (! $party) {
            return null;
        }

        $name = trim(($party->first_name ?? '').' '.($party->last_name ?? ''));

        return [
            'type' => class_basename($party),
            'id' => $party->getKey(),
            'name' => $name !== '' ? $name : ($party->name ?? null),
            'email' => $party->email ?? null,
        ];
    }
}
