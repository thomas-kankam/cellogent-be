<?php

namespace App\Support;

use App\Jobs\FinalizeContactUploadJob;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the upload completion job once every chunk has finished (no giant Bus::batch in memory).
 */
final class ContactUploadFinalizer
{
    public static function markQueued(string $importId, int $clientId, int $groupId): void
    {
        Cache::put(self::key($importId, 'state'), 'queued', 86400);
        Cache::put(self::key($importId, 'client_id'), $clientId, 86400);
        Cache::put(self::key($importId, 'group_id'), $groupId, 86400);
        Cache::put(self::key($importId, 'inserted'), 0, 86400);
        Cache::put(self::key($importId, 'skipped'), 0, 86400);
        Cache::put(self::key($importId, 'failed'), 0, 86400);
        Cache::put(self::key($importId, 'chunks_expected'), 0, 86400);
        Cache::put(self::key($importId, 'chunks_done'), 0, 86400);
        Cache::put(self::key($importId, 'total'), 0, 86400);
        Cache::put(self::key($importId, 'reader_done'), false, 86400);
        Cache::put('contact-group:'.$groupId.':active-import', $importId, 86400);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function status(string $importId): ?array
    {
        $state = Cache::get(self::key($importId, 'state'));

        if ($state === null) {
            return null;
        }

        if ($state === 'completed' || $state === 'failed') {
            $result = Cache::get(self::key($importId, 'result'));

            return is_array($result) ? $result : null;
        }

        $expected = (int) Cache::get(self::key($importId, 'chunks_expected'), 0);
        $done = (int) Cache::get(self::key($importId, 'chunks_done'), 0);

        return [
            'import_id' => $importId,
            'contact_group_id' => (int) Cache::get(self::key($importId, 'group_id'), 0),
            'status' => $state,
            'total' => (int) Cache::get(self::key($importId, 'total'), 0),
            'inserted' => (int) Cache::get(self::key($importId, 'inserted'), 0),
            'skipped' => (int) Cache::get(self::key($importId, 'skipped'), 0),
            'failed' => (int) Cache::get(self::key($importId, 'failed'), 0),
            'chunks_expected' => $expected,
            'chunks_done' => $done,
            'progress' => $expected > 0 ? min(99, (int) floor($done / $expected * 100)) : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function markFinished(string $importId, string $state, array $result): void
    {
        Cache::put(self::key($importId, 'state'), $state, 86400);
        Cache::put(self::key($importId, 'result'), $result, 86400);

        $groupId = Cache::get(self::key($importId, 'group_id'));
        if ($groupId) {
            Cache::forget('contact-group:'.$groupId.':active-import');
        }

        self::forgetImport($importId);
    }

    public static function markFailed(string $importId, int $groupId): void
    {
        if (Cache::get(self::key($importId, 'state')) === 'completed') {
            return;
        }

        self::markFinished($importId, 'failed', [
            'import_id' => $importId,
            'contact_group_id' => $groupId,
            'status' => 'failed',
            'total' => (int) Cache::get(self::key($importId, 'total'), 0),
            'inserted' => (int) Cache::get(self::key($importId, 'inserted'), 0),
            'skipped' => (int) Cache::get(self::key($importId, 'skipped'), 0),
            'failed' => (int) Cache::get(self::key($importId, 'failed'), 0),
            'progress' => 0,
            'message' => 'The upload could not be finished. Try again.',
        ]);
    }

    public static function beginImport(string $importId, string $diskPath): void
    {
        Cache::put(self::key($importId, 'state'), 'processing', 86400);
        Cache::put("contact-import:{$importId}:disk_path", $diskPath, 86400);
        Cache::put("contact-import:{$importId}:inserted", 0, 86400);
        Cache::put("contact-import:{$importId}:skipped", 0, 86400);
        Cache::put("contact-import:{$importId}:failed", 0, 86400);
        Cache::put("contact-import:{$importId}:chunks_expected", 0, 86400);
        Cache::put("contact-import:{$importId}:chunks_done", 0, 86400);
        Cache::put("contact-import:{$importId}:reader_done", false, 86400);
        Cache::forget("contact-import:{$importId}:finalized");
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
