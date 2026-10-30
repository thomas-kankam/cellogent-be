<?php

namespace App\Support;

/**
 * Normalizes Ghana numbers to 233 + 9 digits.
 * 0244000000, 244000000, +233244000000, 233244000000 → 233244000000
 */
final class GhanaPhone
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            return $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '233'.substr($digits, 1);
        }

        if (strlen($digits) === 9) {
            return '233'.$digits;
        }

        return $digits;
    }

    /**
     * Normalized Ghana mobile, or null if the value cannot receive SMS.
     */
    public static function toMobile(?string $value): ?string
    {
        $normalized = self::normalize($value);

        if ($normalized === null || ! preg_match('/^233\d{9}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Laravel rule: 233 followed by exactly 9 digits.
     */
    public static function rule(): string
    {
        return 'regex:/^233\d{9}$/';
    }
}
