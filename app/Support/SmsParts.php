<?php

namespace App\Support;

/**
 * SMS billing units from message length.
 * Default: every 160 characters = 1 unit per recipient.
 * Example: 1–160 → 1, 161–320 → 2, … up to message_max_length.
 */
final class SmsParts
{
    public static function count(string $message): int
    {
        $perUnit = max(1, (int) config('sms.chars_per_unit', 160));
        $length = mb_strlen($message);

        if ($length <= 0) {
            return 1;
        }

        return (int) ceil($length / $perUnit);
    }

    public static function charsPerUnit(): int
    {
        return max(1, (int) config('sms.chars_per_unit', 160));
    }
}
