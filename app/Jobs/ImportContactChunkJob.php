<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Support\GhanaPhone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ImportContactChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    /**
     * @param  list<array<int, mixed>>  $rows
     */
    public function __construct(
        public string $importId,
        public int $clientId,
        public int $groupId,
        public array $rows,
    ) {
        $this->onQueue('contacts-upload');
    }

    public function handle(): void
    {
        $now = now();
        $toInsert = [];
        $seenPhone = [];
        $seenEmail = [];
        $seenDob = [];
        $skipped = 0;
        $failed = 0;

        foreach ($this->rows as $record) {
            $phone = GhanaPhone::normalize($this->cell($record, 'phone_number'));

            if (! $phone) {
                $failed++;

                continue;
            }

            $email = $this->blankToNull($this->cell($record, 'email'));
            $dob = $this->normalizeDob($this->cell($record, 'date_of_birth'));

            if (isset($seenPhone[$phone])
                || ($email && isset($seenEmail[$email]))
                || ($dob && isset($seenDob[$dob]))) {
                $skipped++;

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
                'first_name' => $this->blankToNull($this->cell($record, 'first_name')),
                'last_name' => $this->blankToNull($this->cell($record, 'last_name')),
                'email' => $email,
                'gender' => $this->blankToNull($this->cell($record, 'gender')),
                'phone_number' => $phone,
                'date_of_birth' => $dob,
                'region' => $this->blankToNull($this->cell($record, 'region')),
                'city' => $this->blankToNull($this->cell($record, 'city')),
                'gps_address' => $this->blankToNull($this->cell($record, 'gps_address')),
                'address' => $this->blankToNull($this->cell($record, 'address')),
                'dnd_status' => strtolower(trim((string) $this->cell($record, 'dnd_status'))) === 'active' ? 'active' : 'inactive',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $toInsert = $this->rejectExisting($toInsert, $skipped);

        if ($toInsert !== []) {
            foreach (array_chunk($toInsert, 250) as $batch) {
                Contact::insert($batch);
            }
        }

        Cache::increment("contact-import:{$this->importId}:inserted", count($toInsert));
        Cache::increment("contact-import:{$this->importId}:skipped", $skipped);
        Cache::increment("contact-import:{$this->importId}:failed", $failed);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function rejectExisting(array $rows, int &$skipped): array
    {
        $phones = array_values(array_filter(array_column($rows, 'phone_number')));
        $emails = array_values(array_filter(array_column($rows, 'email')));
        $dobs = array_values(array_filter(array_column($rows, 'date_of_birth')));

        $existing = Contact::query()
            ->where('contact_group_id', $this->groupId)
            ->where(function ($query) use ($phones, $emails, $dobs) {
                if ($phones) {
                    $query->orWhereIn('phone_number', $phones);
                }
                if ($emails) {
                    $query->orWhereIn('email', $emails);
                }
                if ($dobs) {
                    $query->orWhereIn('date_of_birth', $dobs);
                }
            })
            ->get(['phone_number', 'email', 'date_of_birth']);

        $phoneSet = $existing->pluck('phone_number')->filter()->flip()->all();
        $emailSet = $existing->pluck('email')->filter()->flip()->all();
        $dobSet = $existing->pluck('date_of_birth')->filter()->flip()->all();

        $kept = [];

        foreach ($rows as $row) {
            if (isset($phoneSet[$row['phone_number']])
                || ($row['email'] && isset($emailSet[$row['email']]))
                || ($row['date_of_birth'] && isset($dobSet[$row['date_of_birth']]))) {
                $skipped++;

                continue;
            }

            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function cell(array $record, string $key): mixed
    {
        return $record[$key] ?? null;
    }

    protected function normalizeDob(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = $this->blankToNull(is_scalar($value) ? (string) $value : null);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 10);
        }

        $parsed = strtotime($value);

        return $parsed ? date('Y-m-d', $parsed) : $value;
    }

    protected function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
