<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\ContactGroup;
use App\Support\ContactUploadFinalizer;
use App\Support\LongQueue;
use App\Support\XlsxStringCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Streams the xlsx and fans out one ImportContactChunkJob per CHUNK rows.
 * 100k rows = 20 chunk jobs, processed in parallel by the contacts-upload workers.
 */
class ImportContactsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    /** Rows per chunk job. Larger = fewer jobs; each row is a small list, so 5k is ~1MB in Redis. */
    public const CHUNK = 5000;

    /** Order of values inside each queued row (keeps the Redis payload small). */
    public const COLUMNS = [
        'first_name',
        'last_name',
        'email',
        'gender',
        'phone_number',
        'date_of_birth',
        'region',
        'city',
        'gps_address',
        'address',
        'dnd_status',
    ];

    /** Header spellings people commonly use for the phone column. */
    protected const ALIASES = [
        'phone' => 'phone_number',
        'mobile' => 'phone_number',
        'mobile_number' => 'phone_number',
        'phone_no' => 'phone_number',
        'dob' => 'date_of_birth',
    ];

    public function __construct(
        public int $clientId,
        public int $groupId,
        public string $diskPath,
    ) {
        $this->onConnection(LongQueue::connection());
        $this->onQueue('contacts-upload');
    }

    public function uniqueId(): string
    {
        return 'contact-upload-group-'.$this->groupId;
    }

    public function handle(): void
    {
        $group = ContactGroup::query()->find($this->groupId);
        $client = Client::query()->find($this->clientId);

        if (! $group || ! $client || ! Storage::disk('local')->exists($this->diskPath)) {
            return;
        }

        $importId = (string) Str::uuid();
        ContactUploadFinalizer::beginImport($importId, $this->diskPath);

        $reader = new Reader(null, new XlsxStringCache);
        $reader->open(Storage::disk('local')->path($this->diskPath));

        $positions = [];
        $buffer = [];
        $total = 0;
        $isHeader = true;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();

                if ($isHeader) {
                    $positions = $this->columnPositions($cells);
                    $isHeader = false;

                    continue;
                }

                $values = $this->pick($positions, $cells);

                if ($values === null) {
                    continue;
                }

                $total++;
                $buffer[] = $values;

                if (count($buffer) >= self::CHUNK) {
                    $this->dispatchChunk($importId, $buffer);
                    $buffer = [];
                }
            }

            break;
        }

        $reader->close();

        if ($buffer !== []) {
            $this->dispatchChunk($importId, $buffer);
        }

        ContactUploadFinalizer::markReaderComplete($importId, $total);
        ContactUploadFinalizer::maybeFinalize($importId, $this->clientId, $this->groupId, $this->diskPath);
    }

    /**
     * @param  list<list<?string>>  $buffer
     */
    protected function dispatchChunk(string $importId, array $buffer): void
    {
        Cache::increment("contact-import:{$importId}:chunks_expected");
        ImportContactChunkJob::dispatch($importId, $this->clientId, $this->groupId, $this->diskPath, $buffer);
    }

    /**
     * Map each known column to its index in the sheet.
     *
     * @param  list<mixed>  $headerCells
     * @return array<string, int>
     */
    protected function columnPositions(array $headerCells): array
    {
        $positions = [];

        foreach ($headerCells as $index => $cell) {
            $key = strtolower(trim(str_replace([' ', '-'], '_', (string) $cell)));
            $key = self::ALIASES[$key] ?? $key;

            if (in_array($key, self::COLUMNS, true) && ! isset($positions[$key])) {
                $positions[$key] = $index;
            }
        }

        return $positions;
    }

    /**
     * Values in COLUMNS order, or null when the row is completely empty.
     *
     * @param  array<string, int>  $positions
     * @param  list<mixed>  $cells
     * @return list<?string>|null
     */
    protected function pick(array $positions, array $cells): ?array
    {
        $values = [];
        $hasValue = false;

        foreach (self::COLUMNS as $column) {
            $cell = isset($positions[$column]) ? ($cells[$positions[$column]] ?? null) : null;

            if ($cell instanceof \DateTimeInterface) {
                $cell = $cell->format('Y-m-d');
            } elseif (is_float($cell) && floor($cell) === $cell) {
                $cell = number_format($cell, 0, '.', '');
            } elseif ($cell !== null && ! is_scalar($cell)) {
                $cell = null;
            }

            $cell = $cell === null ? null : trim((string) $cell);
            $cell = $cell === '' ? null : $cell;

            if ($cell !== null) {
                $hasValue = true;
            }

            $values[] = $cell;
        }

        return $hasValue ? $values : null;
    }
}
