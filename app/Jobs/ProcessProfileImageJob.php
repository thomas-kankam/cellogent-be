<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Background step after a profile photo is stored.
 * The HTTP request already saved the file; this job is the place to resize/compress later.
 */
class ProcessProfileImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $guard,
        public int $actorId,
        public string $path,
    ) {
        $this->onQueue('uploads');
    }

    public function handle(): void
    {
        if (! Storage::disk('public')->exists($this->path)) {
            Log::warning('Profile image missing for processing', [
                'guard' => $this->guard,
                'actor_id' => $this->actorId,
                'path' => $this->path,
            ]);
        }
    }
}
