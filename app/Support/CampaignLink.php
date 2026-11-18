<?php

namespace App\Support;

use App\Models\Campaign;

/**
 * Builds and verifies short tracking redirect URLs (`/c/{campaign}/{code}`) for campaign link CTAs
 * so clicks are recorded in `campaign_events` before redirecting the recipient to the client's URL.
 */
class CampaignLink
{
    /**
     * True when the CTA is enabled and points to an http(s) URL (not USSD or phone).
     *
     * @param  array<string, mixed>|null  $cta
     */
    public static function shouldWrap(?array $cta): bool
    {
        return self::targetUrl($cta) !== null;
    }

    /**
     * The destination URL to redirect to, or null when the CTA is disabled or not a link.
     *
     * @param  array<string, mixed>|null  $cta
     */
    public static function targetUrl(?array $cta): ?string
    {
        if (! is_array($cta) || $cta === []) {
            return null;
        }

        if (! filter_var($cta['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $type = strtolower(trim((string) ($cta['type'] ?? 'link')));

        if ($type !== '' && $type !== 'link') {
            return null;
        }

        $url = trim((string) ($cta['url'] ?? $cta['value'] ?? ''));

        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }

    /**
     * Short public tracking URL. When `$contactId` is provided, appends a short signed code
     * so we can attribute the click to the exact contact & phone number.
     */
    public static function url(Campaign|int $campaign, ?int $contactId = null): string
    {
        $campaignId = $campaign instanceof Campaign ? (int) $campaign->id : $campaign;
        $base = rtrim((string) config('app.url'), '/');

        if ($contactId === null || $contactId <= 0) {
            return "{$base}/c/{$campaignId}";
        }

        return "{$base}/c/{$campaignId}/".self::encodeContact($campaignId, $contactId);
    }

    /**
     * Sample wrapped URL used to estimate SMS parts before the campaign has an id.
     */
    public static function sampleUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return "{$base}/c/9999/zzzz-000000";
    }

    public static function encodeContact(int $campaignId, int $contactId): string
    {
        $encoded = base_convert((string) $contactId, 10, 36);
        $sig = self::signature($campaignId, $contactId);

        return "{$encoded}-{$sig}";
    }

    public static function decodeContactId(int $campaignId, ?string $code): ?int
    {
        if ($code === null || ! str_contains($code, '-')) {
            return null;
        }

        [$encoded, $sig] = explode('-', $code, 2);

        if ($encoded === '' || $sig === '' || ! ctype_alnum($encoded)) {
            return null;
        }

        $contactId = (int) base_convert($encoded, 36, 10);

        if ($contactId <= 0) {
            return null;
        }

        return hash_equals(self::signature($campaignId, $contactId), $sig) ? $contactId : null;
    }

    protected static function signature(int $campaignId, int $contactId): string
    {
        return substr(hash_hmac('sha256', "campaign-cta:{$campaignId}:{$contactId}", (string) config('app.key')), 0, 6);
    }
}
