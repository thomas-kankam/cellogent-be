<?php

namespace App\Support;

/**
 * Sheets and forms send M or F. The database stores m or f.
 * API responses show Male or Female.
 */
final class ContactGender
{
    public static function store(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'm', 'male' => 'm',
            'f', 'female' => 'f',
            default => null,
        };
    }

    public static function label(?string $stored): ?string
    {
        return match (self::store($stored)) {
            'm' => 'Male',
            'f' => 'Female',
            default => null,
        };
    }
}
