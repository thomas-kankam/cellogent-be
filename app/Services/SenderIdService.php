<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Admin;
use App\Models\Client;
use App\Models\SenderId;
use App\Models\SenderIdBlacklist;
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
        if ($blocked = $this->blocked($name)) {
            return $blocked;
        }

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
    public function update(Client $client, SenderId $senderId, string $name): array
    {
        if ((int) $senderId->client_id !== (int) $client->id) {
            return $this->result(true, 'Sender ID not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        if ($blocked = $this->blocked($name)) {
            return $blocked;
        }

        $taken = SenderId::query()
            ->where('client_id', $client->id)
            ->where('id', '!=', $senderId->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($taken) {
            return $this->result(true, 'You already have this sender ID pending or approved.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $nameChanged = strcasecmp($senderId->name, $name) !== 0;

        $payload = ['name' => $name];

        if ($nameChanged && in_array($senderId->status, ['approved', 'rejected'], true)) {
            $payload['status'] = 'pending';
            $payload['approved_by'] = null;
            $payload['approved_at'] = null;
            $payload['rejected_by'] = null;
            $payload['rejected_at'] = null;
            $payload['rejection_reason'] = null;
        }

        $senderId->update($payload);

        $this->activity()->record('Sender ID updated', 'sender_id', 'updated', 'client', $client, $client, [
            'sender_id' => $senderId->id,
        ]);

        $fresh = $senderId->fresh();
        $message = $fresh->status === 'pending' && $nameChanged
            ? 'Sender ID updated and sent for approval.'
            : 'Sender ID updated.';

        return $this->result(false, $message, ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($fresh));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function approve(Admin $admin, SenderId $senderId): array
    {
        if ($senderId->status !== 'pending') {
            return $this->result(true, 'This sender ID is not pending.', ApiCode::BAD_REQUEST, ApiReason::ALREADY_PROCESSED);
        }

        if ($entry = SenderIdBlacklist::match($senderId->name)) {
            return $this->result(true, 'This sender ID matches the blacklist entry "'.$entry->name.'". Reject it instead.', ApiCode::BAD_REQUEST, ApiReason::SENDER_ID_NOT_ALLOWED);
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

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function delete(Client $client, SenderId $senderId): array
    {
        if ((int) $senderId->client_id !== (int) $client->id) {
            return $this->result(true, 'Sender ID not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $id = $senderId->id;
        $senderId->delete();

        $this->activity()->record('Sender ID deleted', 'sender_id', 'deleted', 'client', $client, $client, [
            'sender_id' => $id,
        ]);

        return $this->result(false, 'Sender ID deleted.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}|null
     */
    protected function blocked(string $name): ?array
    {
        if (! SenderIdBlacklist::match($name)) {
            return null;
        }

        return $this->result(
            true,
            'This sender ID is not allowed. It is reserved or blocked by the networks. Choose a different name.',
            ApiCode::BAD_REQUEST,
            ApiReason::SENDER_ID_NOT_ALLOWED,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function presentBlacklist(SenderIdBlacklist $entry): array
    {
        return [
            'id' => $entry->id,
            'resource' => 'sender_id_blacklist',
            'name' => $entry->name,
            'match_type' => $entry->match_type,
            'source' => $entry->source,
            'reason' => $entry->reason,
            'created_by' => $entry->created_by,
            'created_at' => optional($entry->created_at)->toDateTimeString(),
        ];
    }

    /**
     * @param  array{name: string, match_type?: string, source?: ?string, reason?: ?string}  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function blacklist(Admin $admin, array $input): array
    {
        $normalized = SenderIdBlacklist::normalize($input['name']);

        if ($normalized === '') {
            return $this->result(true, 'Enter a name with letters or digits.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR);
        }

        if (SenderIdBlacklist::query()->where('normalized', $normalized)->exists()) {
            return $this->result(true, 'This name is already blacklisted.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $entry = SenderIdBlacklist::create([
            'name' => $input['name'],
            'normalized' => $normalized,
            'match_type' => $input['match_type'] ?? 'exact',
            'source' => $input['source'] ?? null,
            'reason' => $input['reason'] ?? null,
            'created_by' => $admin->id,
        ]);

        $this->activity()->record('Sender ID blacklisted', 'sender_id', 'blacklisted', 'admin', $admin, null, [
            'blacklist_id' => $entry->id,
            'name' => $entry->name,
            'match_type' => $entry->match_type,
        ]);

        return $this->result(false, 'Sender ID blacklisted.', ApiCode::CREATED, ApiReason::CREATED, $this->presentBlacklist($entry));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function unblacklist(Admin $admin, SenderIdBlacklist $entry): array
    {
        $name = $entry->name;
        $entry->delete();

        $this->activity()->record('Sender ID removed from blacklist', 'sender_id', 'unblacklisted', 'admin', $admin, null, [
            'name' => $name,
        ]);

        return $this->result(false, 'Sender ID removed from the blacklist.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }
}
