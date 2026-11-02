<?php

namespace App\Support;

/**
 * Connection for long-running jobs (see `redis-long` in config/queue.php).
 * Falls back to the default connection when the app is not on Redis (e.g. sync locally).
 */
final class LongQueue
{
    public static function connection(): ?string
    {
        return config('queue.default') === 'redis' ? 'redis-long' : null;
    }
}
