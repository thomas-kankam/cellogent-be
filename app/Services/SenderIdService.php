<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Admin;
use App\Models\Client;
use App\Models\SenderId;
use App\Support\ApiCode;
use App\Support\ApiReason;

class SenderIdService
{
    use LogsActivity;
    use ReturnsServiceResult;

    /**
     * @return array<string, mixed>
     */
    public function present(SenderId $senderId): array
    {
        return [
            'id' => $senderId->id,
            'name' => $senderId->name,
            'client_id' => $senderId->client_id,
            'status' => $senderId->status,
            // 'approved_by' => $senderId->approved_by,
            'approved_at' => optional($senderId->approved_at)->toDateTimeString(),
            // 'rejected_by' => $senderId->rejected_by,
            'rejected_at' => optional($senderId->rejected_at)->toDateTimeString(),
            'rejection_reason' => $senderId->rejection_reason,
            'created_at' => optional($senderId->created_at)->toDateTimeString(),
        ];
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function request(Client $client, string $name): array
    {
        $exists = SenderId::query()
            ->where('client_id', $client->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($exists) {
            return $this->result(true, 'You already have this sender ID pending or approved.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $senderId = SenderId::create([
            'name' => $name,
            'client_id' => $client->id,
            'status' => 'pending',
        ]);

        $this->activity()->senderIdRequested($client, $senderId);

        return $this->result(false, 'Sender ID submitted for approval.', ApiCode::CREATED, ApiReason::CREATED, $this->present($senderId));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function approve(Admin $admin, SenderId $senderId): array
    {
        if ($senderId->status !== 'pending') {
            return $this->result(true, 'This sender ID is not pending.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
        }

        $senderId->update([
            'status' => 'approved',
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        $client = Client::query()->find($senderId->client_id);
        if ($client) {
            $this->activity()->senderIdApproved($admin, $client, $senderId->fresh());
        }

        return $this->result(false, 'Sender ID approved.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($senderId->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function reject(Admin $admin, SenderId $senderId, string $reason): array
    {
        if ($senderId->status !== 'pending') {
            return $this->result(true, 'This sender ID is not pending.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
        }

        $senderId->update([
            'status' => 'rejected',
            'rejected_by' => $admin->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $client = Client::query()->find($senderId->client_id);
        if ($client) {
            $this->activity()->senderIdRejected($admin, $client, $senderId->fresh());
        }

        return $this->result(false, 'Sender ID rejected.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($senderId->fresh()));
    }
}
