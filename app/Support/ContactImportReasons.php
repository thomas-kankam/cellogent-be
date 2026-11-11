<?php

namespace App\Support;

/**
 * Why a spreadsheet row was not added during an upload.
 */
final class ContactImportReasons
{
    public const INVALID_PHONE = 'invalid_phone';

    public const DUPLICATE_PHONE_IN_FILE = 'duplicate_phone_in_file';

    public const DUPLICATE_EMAIL_IN_FILE = 'duplicate_email_in_file';

    public const ALREADY_IN_GROUP = 'already_in_group';

    public const LABELS = [
        self::INVALID_PHONE => 'Missing or invalid Ghana phone number',
        self::DUPLICATE_PHONE_IN_FILE => 'Same phone number appears earlier in this file',
        self::DUPLICATE_EMAIL_IN_FILE => 'Same email appears earlier in this file',
        self::ALREADY_IN_GROUP => 'Phone or email already exists in this group',
    ];

    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{code: string, count: int, message: string}>
     */
    public static function present(array $counts): array
    {
        $rows = [];

        foreach (self::LABELS as $code => $message) {
            $count = (int) ($counts[$code] ?? 0);

            if ($count < 1) {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'count' => $count,
                'message' => $message,
            ];
        }

        return $rows;
    }
}
