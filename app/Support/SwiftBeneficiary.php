<?php

namespace App\Support;

/**
 * Affordable MTN SME (YELLO) data bundles require the recipient to be an approved
 * Swift/MTN beneficiary. Mash Up, retail/non-YELLO data, airtime and other services do not.
 */
final class SwiftBeneficiary
{
    /**
     * Does this catalogue product need a beneficiary check before purchase / reward?
     */
    public static function required(?string $productType, ?string $network): bool
    {
        $type = strtolower(trim((string) $productType));

        // Only plain data bundles — never mashup, airtime, bills, results.
        if ($type !== 'data_bundle' && $type !== 'data') {
            return false;
        }

        $key = self::key($network);

        // "MTN Mash Up" → mtnmashup — must not match plain mtn.
        if ($key === '' || str_contains($key, 'mash')) {
            return false;
        }

        foreach ((array) config('swift.beneficiary_required_networks', ['YELLO', 'MTN']) as $allowed) {
            if ($key === self::key((string) $allowed)) {
                return true;
            }
        }

        return false;
    }

    public static function key(?string $value): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', (string) $value) ?? '');
    }
}
