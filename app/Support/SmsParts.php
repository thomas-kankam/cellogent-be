<?php

namespace App\Support;

/**
 * Counts how many SMS segments (= units per recipient) a message uses.
 * GSM-7: 160 chars single, 153 per part. Anything else is UCS-2: 70 single, 67 per part.
 */
final class SmsParts
{
    protected const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    protected const GSM_EXTENDED = "^{}\\[~]|€\f";

    /** @var array<string, int>|null */
    protected static ?array $table = null;

    public static function count(string $message): int
    {
        $length = self::gsmLength($message);

        if ($length !== null) {
            return $length <= 160 ? 1 : (int) ceil($length / 153);
        }

        $units = intdiv(strlen(mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')), 2);

        return $units <= 70 ? 1 : (int) ceil($units / 67);
    }

    /**
     * GSM-7 length (extended chars count as 2), or null if the text needs Unicode.
     */
    protected static function gsmLength(string $message): ?int
    {
        $table = self::table();
        $length = 0;

        foreach (mb_str_split($message) as $char) {
            if (! isset($table[$char])) {
                return null;
            }

            $length += $table[$char];
        }

        return $length;
    }

    /**
     * @return array<string, int>
     */
    protected static function table(): array
    {
        if (self::$table === null) {
            self::$table = array_fill_keys(mb_str_split(self::GSM), 1)
                + array_fill_keys(mb_str_split(self::GSM_EXTENDED), 2);
        }

        return self::$table;
    }
}
