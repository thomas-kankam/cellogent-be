<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Support\GhanaPhone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ImportContactsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public int $clientId,
        public int $groupId,
        public string $diskPath,
    ) {
        $this->onQueue('contacts-upload');
    }

    public function handle(): void
    {
        $group = ContactGroup::query()->find($this->groupId);
        $client = Client::query()->find($this->clientId);

        if (! $group || ! $client || ! Storage::disk('local')->exists($this->diskPath)) {
            return;
        }

        $fullPath = Storage::disk('local')->path($this->diskPath);
        $handle = fopen($fullPath, 'r');

        if ($handle === false) {
            return;
        }

        $header = fgetcsv($handle);
        $header = array_map(fn ($col) => strtolower(trim((string) $col)), $header ?: []);

        $inserted = 0;
        $skipped = 0;
        $failed = 0;
        $total = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $total++;

            if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }

            $record = $this->mapRow($header, $row);
            $phone = GhanaPhone::normalize($record['phone_number'] ?? null);

            if (! $phone) {
                $failed++;

                continue;
            }

            $email = $this->blankToNull($record['email'] ?? null);
            $dob = $this->blankToNull($record['date_of_birth'] ?? null);

            if ($this->duplicateInGroup($group->id, $phone, $email, $dob)) {
                $skipped++;

                continue;
            }

            try {
                Contact::create([
                    'client_id' => $client->id,
                    'contact_group_id' => $group->id,
                    'first_name' => $this->blankToNull($record['first_name'] ?? null),
                    'last_name' => $this->blankToNull($record['last_name'] ?? null),
                    'email' => $email,
                    'gender' => $this->blankToNull($record['gender'] ?? null),
                    'phone_number' => $phone,
                    'date_of_birth' => $dob,
                    'region' => $this->blankToNull($record['region'] ?? null),
                    'city' => $this->blankToNull($record['city'] ?? null),
                    'gps_address' => $this->blankToNull($record['gps_address'] ?? null),
                    'address' => $this->blankToNull($record['address'] ?? null),
                    'dnd_status' => ($record['dnd_status'] ?? '') === 'active' ? 'active' : 'inactive',
                ]);
                $inserted++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        fclose($handle);

        $count = Contact::query()->where('contact_group_id', $group->id)->count();
        $group->update(['count_of_contacts' => $count]);

        Storage::disk('local')->delete($this->diskPath);

        SendContactUploadMailJob::dispatch($client->email, [
            'group_name' => $group->name,
            'total' => $total,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'failed' => $failed,
            'count_of_contacts' => $count,
        ], trim($client->first_name.' '.$client->last_name) ?: null);
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $row
     * @return array<string, string>
     */
    protected function mapRow(array $header, array $row): array
    {
        $mapped = [];

        foreach ($header as $i => $key) {
            $mapped[$key] = isset($row[$i]) ? trim((string) $row[$i]) : '';
        }

        return $mapped;
    }

    protected function duplicateInGroup(int $groupId, string $phone, ?string $email, ?string $dob): bool
    {
        return Contact::query()
            ->where('contact_group_id', $groupId)
            ->where(function ($query) use ($phone, $email, $dob) {
                $query->where('phone_number', $phone);

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
