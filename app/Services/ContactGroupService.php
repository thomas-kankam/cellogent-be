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

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function stats(Client $client): array
    {
        $clientId = $client->id;
        $monthStart = now()->startOfMonth();

        $activeGroupIds = ContactGroup::query()->where('client_id', $clientId)->pluck('id');
        $archivedGroupIds = ContactGroup::onlyTrashed()->where('client_id', $clientId)->pluck('id');
        $knownGroupIds = $activeGroupIds->merge($archivedGroupIds)->unique()->values();

        $activeContacts = Contact::query()
            ->where('client_id', $clientId)
            ->visible()
            ->whereIn('contact_group_id', $activeGroupIds);

        $archivedContacts = Contact::query()
            ->where('client_id', $clientId)
            ->visible()
            ->whereIn('contact_group_id', $archivedGroupIds);

        // Group force-deleted (or null) but contact row still around.
        $orphanedContacts = Contact::query()
            ->where('client_id', $clientId)
            ->visible();

        if ($knownGroupIds->isEmpty()) {
            // No groups left → every remaining contact is orphaned.
        } else {
            $orphanedContacts->where(function ($query) use ($knownGroupIds) {
                $query->whereNull('contact_group_id')
                    ->orWhereNotIn('contact_group_id', $knownGroupIds);
            });
        }

        return $this->result(false, 'Contact stats loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
            'total_groups' => $activeGroupIds->count(),
            'archived_groups' => $archivedGroupIds->count(),
            // Active contacts only (in non-archived groups) — what the dashboard should show.
            'total_contacts' => (clone $activeContacts)->count(),
            'archived_contacts' => (clone $archivedContacts)->count(),
            'orphaned_contacts' => (clone $orphanedContacts)->count(),
            'new_this_month' => (clone $activeContacts)
                ->where('created_at', '>=', $monthStart)
                ->count(),
        ]);
    }

    public function refreshCount(ContactGroup $group): ContactGroup
    {
        $group->update([
            'count_of_contacts' => Contact::query()
                ->where('contact_group_id', $group->id)
                ->visible()
                ->count(),
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
     * Soft delete (archive). Contacts stay on the group so restore brings them back,
     * but they no longer count toward total_contacts until the group is restored.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function softDelete(Client $client, ContactGroup $group): array
    {
        $contactCount = (int) $group->count_of_contacts;
        $group->delete();
        $archived = ContactGroup::withTrashed()->find($group->id);

        $this->activity()->record('Contact group archived', 'contact_group', 'deleted', 'client', $client, $client, [
            'group_id' => $group->id,
            'contacts_kept' => $contactCount,
        ]);

        return $this->result(
            false,
            'Contact group archived. Its contacts are hidden from active stats until you restore the group.',
            ApiCode::SUCCESS,
            ApiReason::SUCCESS,
            $this->present($archived),
        );
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
     * Permanently remove the group and all contacts in it.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function forceDelete(Client $client, ContactGroup $group): array
    {
        $id = $group->id;
        $removed = Contact::query()->where('contact_group_id', $id)->delete();
        $group->forceDelete();

        $this->activity()->record('Contact group permanently removed', 'contact_group', 'deleted', 'client', $client, $client, [
            'group_id' => $id,
            'contacts_removed' => $removed,
        ]);

        return $this->result(
            false,
            $removed > 0
                ? "Contact group permanently removed along with {$removed} contact(s)."
                : 'Contact group permanently removed.',
            ApiCode::SUCCESS,
            ApiReason::SUCCESS,
        );
    }
}
