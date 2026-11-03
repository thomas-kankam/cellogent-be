<?php

namespace App\Support;

/**
 * Calendar dates (e.g. contact date_of_birth).
 * Accepts 1994-05-12, 12/05/1994, 12-05-1994, 12.05.1994 (day, then month, then year).
 * Stored as Y-m-d. Blank stays blank. Anything else is rejected.
 */
final class ContactDate
{
    public static function store(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // Drop a time part if a client sends 1994-05-12 00:00:00
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\b/', $value, $match)) {
            $value = $match[1];
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match)) {
            return self::make((int) $match[1], (int) $match[2], (int) $match[3]);
        }

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $match)) {
            return self::make((int) $match[3], (int) $match[2], (int) $match[1]);
        }

        return null;
    }

    protected static function make(int $year, int $month, int $day): ?string
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
