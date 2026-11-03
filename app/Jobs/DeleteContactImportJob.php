<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Support\LongQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes every contact that came from one upload, then deletes the upload row.
 */
class DeleteContactImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    protected const DELETE_SIZE = 1000;

    public function __construct(
        public string $importId,
        public int $groupId,
    ) {
        $this->onConnection(LongQueue::connection());
        $this->onQueue('contacts-upload');
    }

    public function handle(): void
    {
        $import = ContactImport::query()->find($this->importId);

        if (! $import || $import->status !== 'deleting') {
            return;
        }

        do {
            $ids = Contact::query()
                ->where('contact_import_id', $this->importId)
                ->limit(self::DELETE_SIZE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            Contact::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::DELETE_SIZE);

        $import->delete();

        $group = ContactGroup::query()->find($this->groupId);

        if ($group) {
            $group->update([
                'count_of_contacts' => Contact::query()
                    ->where('contact_group_id', $group->id)
                    ->visible()
                    ->count(),
            ]);
        }
    }
}
