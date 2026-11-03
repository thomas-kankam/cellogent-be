<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Support\ContactImportReasons;
use App\Support\ContactUploadFinalizer;
use App\Support\LongQueue;
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
        $this->onConnection(LongQueue::connection());
        $this->onQueue('contacts-upload');
    }

    public function handle(): void
    {
        $group = ContactGroup::query()->find($this->groupId);
        $client = Client::query()->find($this->clientId);

        $count = Contact::query()
            ->where('contact_group_id', $this->groupId)
            ->visible()
            ->count();

        if ($group) {
            $group->update(['count_of_contacts' => $count]);
        }

        Storage::disk('local')->delete($this->diskPath);

        $inserted = (int) Cache::get("contact-import:{$this->importId}:inserted", 0);
        $skipped = (int) Cache::get("contact-import:{$this->importId}:skipped", 0);
        $failed = (int) Cache::get("contact-import:{$this->importId}:failed", 0);
        $skipReasons = ContactUploadFinalizer::liveReasons($this->importId);

        if ($client) {
            SendContactUploadMailJob::dispatch($client->email, [
                'group_name' => $group?->name ?? 'Contact group',
                'total' => $this->total,
                'inserted' => $inserted,
                'skipped' => $skipped,
                'failed' => $failed,
                'count_of_contacts' => $count,
                'reasons' => ContactImportReasons::present($skipReasons),
            ], trim($client->first_name.' '.$client->last_name) ?: null);
        }

        ContactUploadFinalizer::markFinished($this->importId, 'completed', [
            'import_id' => $this->importId,
            'contact_group_id' => $this->groupId,
            'status' => 'completed',
            'total' => $this->total,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'failed' => $failed,
            'skip_reasons' => $skipReasons,
            'progress' => 100,
            'count_of_contacts' => $count,
        ]);
    }
}
