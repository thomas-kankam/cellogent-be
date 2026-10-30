<?php

namespace App\Support;

use App\Jobs\FinalizeContactUploadJob;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the upload completion job once every chunk has finished (no giant Bus::batch in memory).
 */
final class ContactUploadFinalizer
{
    public static function beginImport(string $importId, string $diskPath): void
    {
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
}
