<?php

namespace App\Support;

use App\Jobs\FinalizeContactUploadJob;
use App\Models\ContactImport;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the upload completion job once every chunk has finished (no giant Bus::batch in memory).
 */
final class ContactUploadFinalizer
{
    public static function markQueued(string $importId, int $clientId, int $groupId): void
    {
        ContactImport::query()->updateOrCreate(
            ['id' => $importId],
            [
                'client_id' => $clientId,
                'contact_group_id' => $groupId,
                'status' => 'queued',
                'total' => 0,
                'inserted' => 0,
                'skipped' => 0,
                'failed' => 0,
                'chunks_expected' => 0,
                'chunks_done' => 0,
                'message' => null,
            ],
        );

        self::resetCounters($importId);
    }

    /**
     * @return array<string, int>
     */
    public static function liveCounts(string $importId): array
    {
        return [
            'total' => (int) Cache::get(self::key($importId, 'total'), 0),
            'inserted' => (int) Cache::get(self::key($importId, 'inserted'), 0),
            'skipped' => (int) Cache::get(self::key($importId, 'skipped'), 0),
            'failed' => (int) Cache::get(self::key($importId, 'failed'), 0),
            'chunks_expected' => (int) Cache::get(self::key($importId, 'chunks_expected'), 0),
            'chunks_done' => (int) Cache::get(self::key($importId, 'chunks_done'), 0),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function status(string $importId): ?array
    {
        $row = ContactImport::query()->find($importId);

        return $row?->present();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function markFinished(string $importId, string $state, array $result): void
    {
        ContactImport::query()->whereKey($importId)->update([
            'status' => $state,
            'total' => (int) ($result['total'] ?? 0),
            'inserted' => (int) ($result['inserted'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'chunks_expected' => (int) Cache::get(self::key($importId, 'chunks_expected'), 0),
            'chunks_done' => (int) Cache::get(self::key($importId, 'chunks_done'), 0),
            'message' => $result['message'] ?? null,
        ]);

        self::forgetImport($importId);
    }

    public static function markFailed(string $importId, int $groupId): void
    {
        $row = ContactImport::query()->find($importId);

        if (! $row || $row->status === 'completed') {
            return;
        }

        $live = self::liveCounts($importId);

        self::markFinished($importId, 'failed', [
            'total' => $live['total'],
            'inserted' => $live['inserted'],
            'skipped' => $live['skipped'],
            'failed' => $live['failed'],
            'message' => 'The upload could not be finished. Try again.',
        ]);
    }

    public static function beginImport(string $importId, string $diskPath): void
    {
        ContactImport::query()->whereKey($importId)->update(['status' => 'processing']);
        self::resetCounters($importId);
        Cache::put(self::key($importId, 'disk_path'), $diskPath, 86400);
    }

    protected static function resetCounters(string $importId): void
    {
        Cache::put(self::key($importId, 'inserted'), 0, 86400);
        Cache::put(self::key($importId, 'skipped'), 0, 86400);
        Cache::put(self::key($importId, 'failed'), 0, 86400);
        Cache::put(self::key($importId, 'chunks_expected'), 0, 86400);
        Cache::put(self::key($importId, 'chunks_done'), 0, 86400);
        Cache::put(self::key($importId, 'total'), 0, 86400);
        Cache::put(self::key($importId, 'reader_done'), false, 86400);
        Cache::forget(self::key($importId, 'finalized'));
    }

    public static function markReaderComplete(string $importId, int $totalRows): void
    {
        Cache::put("contact-import:{$importId}:total", $totalRows, 86400);
        Cache::put("contact-import:{$importId}:reader_done", true, 86400);
    }

    public static function maybeFinalize(string $importId, int $clientId, int $groupId, string $diskPath): void
    {
        if (! Cache::get("contact-import:{$importId}:reader_done")) {
            return;
        }

        $expected = (int) Cache::get("contact-import:{$importId}:chunks_expected", 0);
        $done = (int) Cache::get("contact-import:{$importId}:chunks_done", 0);

        if ($done < $expected) {
            return;
        }

        $lock = Cache::lock("contact-import:{$importId}:finalize-lock", 120);

        if (! $lock->get()) {
            return;
        }

        try {
            if (Cache::get("contact-import:{$importId}:finalized")) {
                return;
            }

            Cache::put("contact-import:{$importId}:finalized", true, 86400);

            $total = (int) Cache::get("contact-import:{$importId}:total", 0);

            FinalizeContactUploadJob::dispatch($importId, $clientId, $groupId, $diskPath, $total);
        } finally {
            $lock->release();
        }
    }

    public static function forgetImport(string $importId): void
    {
        foreach ([
            'inserted', 'skipped', 'failed', 'chunks_expected', 'chunks_done',
            'reader_done', 'total', 'finalized', 'disk_path',
        ] as $key) {
            Cache::forget("contact-import:{$importId}:{$key}");
        }
    }

    protected static function key(string $importId, string $name): string
    {
        return "contact-import:{$importId}:{$name}";
    }
}
