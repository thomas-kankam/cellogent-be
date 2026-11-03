<?php

namespace App\Jobs;

use App\Support\ContactDate;
use App\Support\ContactGender;
use App\Support\ContactImportReasons;
use App\Support\ContactUploadFinalizer;
use App\Support\GhanaPhone;
use App\Support\LongQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cleans one chunk of rows and bulk-inserts them.
 * Duplicates against existing contacts are dropped by the group unique indexes (INSERT IGNORE).
 */
class ImportContactChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    protected const INSERT_SIZE = 1000;

    /**
     * @param  list<list<?string>>  $rows  values in ImportContactsJob::COLUMNS order
     */
    public function __construct(
        public string $importId,
        public int $clientId,
        public int $groupId,
        public string $diskPath,
        public array $rows,
    ) {
        $this->onConnection(LongQueue::connection());
        $this->onQueue('contacts-upload');
    }

    public function handle(): void
    {
        $now = now()->toDateTimeString();
        $toInsert = [];
        $seenPhone = [];
        $seenEmail = [];
        $seenDob = [];
        $skipped = 0;
        $failed = 0;
        $reasons = array_fill_keys(ContactImportReasons::keys(), 0);

        foreach ($this->rows as $values) {
            $record = array_combine(ImportContactsJob::COLUMNS, $values);
            $phone = GhanaPhone::toMobile($record['phone_number']);

            if (! $phone) {
                $failed++;
                $reasons[ContactImportReasons::INVALID_PHONE]++;

                continue;
            }

            $email = $record['email'] !== null ? strtolower($this->limit($record['email'])) : null;
            $dob = ContactDate::store($record['date_of_birth']);

            if (isset($seenPhone[$phone])) {
                $skipped++;
                $reasons[ContactImportReasons::DUPLICATE_PHONE_IN_FILE]++;

                continue;
            }

            if ($email && isset($seenEmail[$email])) {
                $skipped++;
                $reasons[ContactImportReasons::DUPLICATE_EMAIL_IN_FILE]++;

                continue;
            }

            if ($dob && isset($seenDob[$dob])) {
                $skipped++;
                $reasons[ContactImportReasons::DUPLICATE_DOB_IN_FILE]++;

                continue;
            }

            $seenPhone[$phone] = true;
            if ($email) {
                $seenEmail[$email] = true;
            }
            if ($dob) {
                $seenDob[$dob] = true;
            }

            $toInsert[] = [
                'client_id' => $this->clientId,
                'contact_group_id' => $this->groupId,
                'contact_import_id' => $this->importId,
                'first_name' => $this->limit($record['first_name']),
                'last_name' => $this->limit($record['last_name']),
                'email' => $email,
                'gender' => ContactGender::store($record['gender']),
                'phone_number' => $phone,
                'date_of_birth' => $dob,
                'region' => $this->limit($record['region']),
                'city' => $this->limit($record['city']),
                'gps_address' => $this->limit($record['gps_address']),
                'address' => $this->limit($record['address']),
                'dnd_status' => strtolower((string) $record['dnd_status']) === 'active' ? 'active' : 'inactive',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $inserted = 0;

        foreach (array_chunk($toInsert, self::INSERT_SIZE) as $batch) {
            $inserted += DB::transaction(fn () => DB::table('contacts')->insertOrIgnore($batch), 3);
        }

        $alreadyInGroup = count($toInsert) - $inserted;
        $skipped += $alreadyInGroup;
        $reasons[ContactImportReasons::ALREADY_IN_GROUP] += $alreadyInGroup;

        Cache::increment("contact-import:{$this->importId}:inserted", $inserted);
        Cache::increment("contact-import:{$this->importId}:skipped", $skipped);
        Cache::increment("contact-import:{$this->importId}:failed", $failed);
        Cache::increment("contact-import:{$this->importId}:chunks_done");

        foreach ($reasons as $code => $count) {
            if ($count > 0) {
                Cache::increment("contact-import:{$this->importId}:reason:{$code}", $count);
            }
        }

        ContactUploadFinalizer::maybeFinalize($this->importId, $this->clientId, $this->groupId, $this->diskPath);
    }

    protected function limit(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 255);
    }
}
