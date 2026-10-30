<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\ContactGroup;
use App\Support\ContactUploadFinalizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;

class ImportContactsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public const CHUNK = 500;

    public function __construct(
        public int $clientId,
        public int $groupId,
        public string $diskPath,
    ) {
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

        $fullPath = Storage::disk('local')->path($this->diskPath);
        $reader = new Reader;
        $reader->open($fullPath);

        $header = [];
        $buffer = [];
        $total = 0;
        $isHeader = true;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();

                if ($isHeader) {
                    $header = array_map(function ($col) {
                        return strtolower(trim(str_replace([' ', '-'], '_', (string) $col)));
                    }, $cells);
                    $isHeader = false;

                    continue;
                }

                if ($this->rowIsEmpty($cells)) {
                    continue;
                }

                $total++;
                $buffer[] = $this->mapRow($header, $cells);

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
     * @param  list<array<string, mixed>>  $buffer
     */
    protected function dispatchChunk(string $importId, array $buffer): void
    {
        Cache::increment("contact-import:{$importId}:chunks_expected");
        ImportContactChunkJob::dispatch($importId, $this->clientId, $this->groupId, $this->diskPath, $buffer);
    }

    /**
     * @param  list<mixed>  $header
     * @param  list<mixed>  $row
     * @return array<string, mixed>
     */
    protected function mapRow(array $header, array $row): array
    {
        $mapped = [];

        foreach ($header as $i => $key) {
            $mapped[$key] = $row[$i] ?? null;
        }

        return $mapped;
    }

    /**
     * @param  list<mixed>  $cells
     */
    protected function rowIsEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell instanceof \DateTimeInterface) {
                return false;
            }

            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
