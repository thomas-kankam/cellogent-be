<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Jobs\DeleteContactImportJob;
use App\Jobs\ImportContactsJob;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\ContactDate;
use App\Support\ContactGender;
use App\Support\ContactUploadFinalizer;
use App\Support\GhanaPhone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ContactService
{
    use LogsActivity;
    use ReturnsServiceResult;

    public function __construct(protected ContactGroupService $groups)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'client_id' => $contact->client_id,
            'contact_group_id' => $contact->contact_group_id,
            'contact_import_id' => $contact->contact_import_id,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'email' => $contact->email,
            'gender' => ContactGender::label($contact->gender),
            'phone_number' => $contact->phone_number,
            'date_of_birth' => $contact->date_of_birth,
            'region' => $contact->region,
            'city' => $contact->city,
            'gps_address' => $contact->gps_address,
            'address' => $contact->address,
            'dnd_status' => $contact->dnd_status,
            'created_at' => optional($contact->created_at)->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function create(Client $client, ContactGroup $group, array $input): array
    {
        $phone = GhanaPhone::toMobile($input['phone_number'] ?? null);
        $email = $this->blankToNull($input['email'] ?? null);
        $dob = ContactDate::store($input['date_of_birth'] ?? null);

        if (! $phone) {
            return $this->result(true, 'A valid Ghana phone number is required.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR);
        }

        if ($this->clash($group->id, $phone, $email, $dob)) {
            return $this->result(true, 'This phone, email, or date of birth is already in this group.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $contact = Contact::create([
            'client_id' => $client->id,
            'contact_group_id' => $group->id,
            'first_name' => $input['first_name'] ?? null,
            'last_name' => $input['last_name'] ?? null,
            'email' => $email,
            'gender' => ContactGender::store($input['gender'] ?? null),
            'phone_number' => $phone,
            'date_of_birth' => $dob,
            'region' => $input['region'] ?? null,
            'city' => $input['city'] ?? null,
            'gps_address' => $input['gps_address'] ?? null,
            'address' => $input['address'] ?? null,
            'dnd_status' => $input['dnd_status'] ?? 'inactive',
        ]);

        $this->groups->refreshCount($group);

        return $this->result(false, 'Contact added.', ApiCode::CREATED, ApiReason::CREATED, $this->present($contact));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function update(ContactGroup $group, Contact $contact, array $input): array
    {
        $phone = array_key_exists('phone_number', $input)
            ? GhanaPhone::toMobile($input['phone_number'])
            : $contact->phone_number;
        $email = array_key_exists('email', $input) ? $this->blankToNull($input['email']) : $contact->email;
        $dob = array_key_exists('date_of_birth', $input) ? ContactDate::store($input['date_of_birth']) : $contact->date_of_birth;

        if (! $phone) {
            return $this->result(true, 'A valid Ghana phone number is required.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR);
        }

        if ($this->clash($group->id, $phone, $email, $dob, $contact->id)) {
            return $this->result(true, 'This phone, email, or date of birth is already in this group.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        $contact->update([
            'first_name' => $input['first_name'] ?? $contact->first_name,
            'last_name' => $input['last_name'] ?? $contact->last_name,
            'email' => $email,
            'gender' => array_key_exists('gender', $input) ? ContactGender::store($input['gender']) : $contact->gender,
            'phone_number' => $phone,
            'date_of_birth' => $dob,
            'region' => $input['region'] ?? $contact->region,
            'city' => $input['city'] ?? $contact->city,
            'gps_address' => $input['gps_address'] ?? $contact->gps_address,
            'address' => $input['address'] ?? $contact->address,
            'dnd_status' => $input['dnd_status'] ?? $contact->dnd_status,
        ]);

        return $this->result(false, 'Contact updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($contact->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function setDnd(ContactGroup $group, Contact $contact, string $status): array
    {
        $contact->update(['dnd_status' => $status]);

        return $this->result(false, 'DND updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($contact->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function delete(ContactGroup $group, Contact $contact): array
    {
        $contact->delete();
        $this->groups->refreshCount($group);

        return $this->result(false, 'Contact removed from this group.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function queueUpload(Client $client, ContactGroup $group, UploadedFile $file): array
    {
        $active = ContactImport::query()
            ->where('contact_group_id', $group->id)
            ->whereIn('status', ['queued', 'processing'])
            ->latest('created_at')
            ->first();

        if ($active) {
            return $this->result(true, 'An upload is already running for this group.', ApiCode::BAD_REQUEST, ApiReason::INVALID, $active->present());
        }

        $directory = 'contact-uploads/'.$client->id;
        $filename = Str::uuid()->toString().'.xlsx';
        $name = $file->storeAs($directory, $filename, 'local');

        $importId = (string) Str::uuid();
        ContactUploadFinalizer::markQueued($importId, $client->id, $group->id, [
            'file_name' => $this->originalFileName($file),
            'file_size' => (int) $file->getSize(),
            'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
        ]);

        ImportContactsJob::dispatch($importId, $client->id, $group->id, $name);

        $this->activity()->record('Contact upload queued', 'contact_group', 'created', 'client', $client, $client, [
            'group_id' => $group->id,
            'import_id' => $importId,
        ]);

        return $this->result(
            false,
            'Upload queued. Poll the status url until status is completed.',
            ApiCode::CREATED,
            ApiReason::CREATED,
            ContactImport::query()->findOrFail($importId)->present(),
        );
    }

    protected function originalFileName(UploadedFile $file): string
    {
        $name = trim(str_replace(['\\', '/'], '', $file->getClientOriginalName()));

        return $name === '' ? 'contacts.xlsx' : mb_substr($name, 0, 255);
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function uploadStatus(ContactGroup $group, string $importId): array
    {
        $row = ContactImport::query()
            ->where('contact_group_id', $group->id)
            ->listable()
            ->whereKey($importId)
            ->first();

        if (! $row) {
            return $this->result(true, 'Upload not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        return $this->result(false, 'Upload status loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $row->present());
    }

    /**
     * Mark the upload as deleting immediately (so the list hides it), then remove its contacts in a job.
     *
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function queueDeleteUpload(Client $client, ContactGroup $group, ContactImport $import): array
    {
        if ((int) $import->contact_group_id !== (int) $group->id) {
            return $this->result(true, 'Upload not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        if (in_array($import->status, ['queued', 'processing'], true)) {
            return $this->result(true, 'Wait for this upload to finish before deleting it.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if ($import->status === 'deleting') {
            return $this->result(true, 'Upload not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $import->update([
            'status' => 'deleting',
            'message' => 'Deletion queued.',
        ]);

        // Count drops now so the group total is correct while the job still runs.
        $this->groups->refreshCount($group);

        DeleteContactImportJob::dispatch($import->id, $group->id);

        $this->activity()->record('Contact upload delete queued', 'contact_group', 'deleted', 'client', $client, $client, [
            'group_id' => $group->id,
            'import_id' => $import->id,
            'file_name' => $import->file_name,
        ]);

        return $this->result(false, 'Upload deletion queued. It will disappear from the list now.', ApiCode::SUCCESS, ApiReason::SUCCESS, [
            'import_id' => $import->id,
            'contact_group_id' => $group->id,
            'status' => 'deleting',
        ]);
    }

    protected function clash(int $groupId, ?string $phone, ?string $email, ?string $dob, ?int $ignoreId = null): bool
    {
        return Contact::query()
            ->where('contact_group_id', $groupId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function ($query) use ($phone, $email, $dob) {
                if ($phone) {
                    $query->orWhere('phone_number', $phone);
                }
                if ($email) {
                    $query->orWhere('email', $email);
                }
                if ($dob) {
                    $query->orWhere('date_of_birth', $dob);
                }
            })
            ->exists();
    }

    protected function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
