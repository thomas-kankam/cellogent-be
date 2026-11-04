<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Shared messages-per-second budget across all workers, one budget per lane
 * (quick, bulk, dlr). Callers block until their messages fit in the current second.
 */
final class SmsThrottle
{
    public static function await(string $lane, int $messages): void
    {
        $tps = max(1, (int) config("sms.throttle.{$lane}", 10));
        $messages = max(1, $messages);

        while (true) {
            $second = time();
            $key = "sms-tps:{$lane}:{$second}";

            Cache::add($key, 0, 5);
            $used = (int) Cache::increment($key, $messages);

            // A request bigger than the whole budget is allowed alone in an empty second.
            if ($used <= $tps || $used === $messages) {
                return;
            }

            Cache::decrement($key, $messages);

            $wait = (int) ((($second + 1) - microtime(true)) * 1_000_000);
            usleep(max(1_000, $wait));
        }
    }
}
