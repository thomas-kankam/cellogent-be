<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Support\ContactUploadFinalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class FinalizeContactUploadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $importId,
        public int $clientId,
        public int $groupId,
        public string $diskPath,
        public int $total,
    ) {
        $this->onQueue('contacts-upload');
    }

    public function handle(): void
    {
        $group = ContactGroup::query()->find($this->groupId);
        $client = Client::query()->find($this->clientId);

        $count = Contact::query()->where('contact_group_id', $this->groupId)->count();

        if ($group) {
            $group->update(['count_of_contacts' => $count]);
        }

        Storage::disk('local')->delete($this->diskPath);

        if ($client) {
            SendContactUploadMailJob::dispatch($client->email, [
                'group_name' => $group?->name ?? 'Contact group',
                'total' => $this->total,
                'inserted' => (int) Cache::get("contact-import:{$this->importId}:inserted", 0),
                'skipped' => (int) Cache::get("contact-import:{$this->importId}:skipped", 0),
                'failed' => (int) Cache::get("contact-import:{$this->importId}:failed", 0),
                'count_of_contacts' => $count,
            ], trim($client->first_name.' '.$client->last_name) ?: null);
        }

        Cache::forget("contact-import:{$this->importId}:inserted");
        Cache::forget("contact-import:{$this->importId}:skipped");
        Cache::forget("contact-import:{$this->importId}:failed");

        ContactUploadFinalizer::forgetImport($this->importId);
    }
}
