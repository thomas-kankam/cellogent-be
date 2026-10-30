<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Support\ApiCode;
use App\Support\ApiReason;

class ContactGroupService
{
    use LogsActivity;
    use ReturnsServiceResult;

    /**
     * @return array<string, mixed>
     */
    public function present(ContactGroup $group): array
    {
        return [
            'id' => $group->id,
            'client_id' => $group->client_id,
            'name' => $group->name,
            'description' => $group->description,
            'count_of_contacts' => (int) $group->count_of_contacts,
            'created_at' => optional($group->created_at)->toDateTimeString(),
            'updated_at' => optional($group->updated_at)->toDateTimeString(),
            'deleted_at' => optional($group->deleted_at)->toDateTimeString(),
        ];
    }

    public function refreshCount(ContactGroup $group): ContactGroup
    {
        $group->update([
            'count_of_contacts' => Contact::query()->where('contact_group_id', $group->id)->count(),
        ]);

        return $group->fresh();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function create(Client $client, array $input): array
    {
        $group = ContactGroup::create([
            'client_id' => $client->id,
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'count_of_contacts' => 0,
        ]);

        $this->activity()->record('Contact group created', 'contact_group', 'created', 'client', $client, $client, [
            'group_id' => $group->id,
        ]);

        return $this->result(false, 'Contact group created.', ApiCode::CREATED, ApiReason::CREATED, $this->present($group));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function update(Client $client, ContactGroup $group, array $input): array
    {
        $group->update([
            'name' => $input['name'] ?? $group->name,
            'description' => array_key_exists('description', $input) ? $input['description'] : $group->description,
        ]);

        $this->activity()->record('Contact group updated', 'contact_group', 'updated', 'client', $client, $client, [
            'group_id' => $group->id,
        ]);

        return $this->result(false, 'Contact group updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($group->fresh()));
    }

    /**
     * Soft delete. Contacts stay.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function softDelete(Client $client, ContactGroup $group): array
    {
        $group->delete();
        $archived = ContactGroup::withTrashed()->find($group->id);

        $this->activity()->record('Contact group archived', 'contact_group', 'deleted', 'client', $client, $client, [
            'group_id' => $group->id,
        ]);

        return $this->result(false, 'Contact group archived. Contacts were not removed.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($archived));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function restore(Client $client, ContactGroup $group): array
    {
        $group->restore();
        $group = $this->refreshCount($group);

        $this->activity()->record('Contact group restored', 'contact_group', 'updated', 'client', $client, $client, [
            'group_id' => $group->id,
        ]);

        return $this->result(false, 'Contact group restored.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($group));
    }

    /**
     * Permanently remove the group row. Contacts stay, still owned by the client.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function forceDelete(Client $client, ContactGroup $group): array
    {
        $id = $group->id;
        $group->forceDelete();

        $this->activity()->record('Contact group permanently removed', 'contact_group', 'deleted', 'client', $client, $client, [
            'group_id' => $id,
        ]);

        return $this->result(false, 'Contact group permanently removed. Contacts were not deleted.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }
}
