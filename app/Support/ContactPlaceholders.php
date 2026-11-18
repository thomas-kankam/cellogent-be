<?php

namespace App\Support;

use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contact merge placeholders shared by quick SMS, bulk SMS, campaigns (promotion & occasion)
 * and surveys. Any contact missing one or more selected contact placeholders is excluded.
 */
class ContactPlaceholders
{
    /**
     * Columns on the `contacts` table that can be referenced in SMS templates.
     *
     * @var list<string>
     */
    public const CONTACT_COLUMNS = [
        'first_name',
        'last_name',
        'email',
        'gender',
        'date_of_birth',
        'region',
        'city',
        'gps_address',
        'address',
        'phone_number',
    ];

    /**
     * Normalized placeholder key (lowercase, no underscores) → required `contacts` column(s).
     *
     * @var array<string, list<string>>
     */
    protected const PLACEHOLDER_COLUMNS = [
        'firstname' => ['first_name'],
        'lastname' => ['last_name'],
        'name' => ['first_name'],
        'fullname' => ['first_name', 'last_name'],
        'email' => ['email'],
        'gender' => ['gender'],
        'dob' => ['date_of_birth'],
        'dateofbirth' => ['date_of_birth'],
        'region' => ['region'],
        'city' => ['city'],
        'gpsaddress' => ['gps_address'],
        'address' => ['address'],
        'phonenumber' => ['phone_number'],
        'phone' => ['phone_number'],
    ];

    /**
     * Canonical camelCase label for each normalized placeholder (used in validation/error messages).
     *
     * @var array<string, string>
     */
    protected const PLACEHOLDER_LABELS = [
        'firstname' => 'firstName',
        'lastname' => 'lastName',
        'name' => 'name',
        'fullname' => 'fullName',
        'email' => 'email',
        'gender' => 'gender',
        'dob' => 'dob',
        'dateofbirth' => 'dob',
        'region' => 'region',
        'city' => 'city',
        'gpsaddress' => 'gpsAddress',
        'address' => 'address',
        'phonenumber' => 'phoneNumber',
        'phone' => 'phoneNumber',
    ];

    /**
     * Return all `contacts` columns that must be non-empty for `$template`.
     *
     * @return list<string>
     */
    public static function requiredColumns(string $template): array
    {
        $columns = [];

        foreach (self::extractKeys($template) as $key) {
            foreach (self::PLACEHOLDER_COLUMNS[$key] ?? [] as $column) {
                $columns[$column] = true;
            }
        }

        return array_keys($columns);
    }

    /**
     * Human-readable list of contact placeholders found in `$template`.
     *
     * @return list<string>
     */
    public static function requiredPlaceholders(string $template): array
    {
        $labels = [];

        foreach (self::extractKeys($template) as $key) {
            if (isset(self::PLACEHOLDER_LABELS[$key])) {
                $labels[self::PLACEHOLDER_LABELS[$key]] = true;
            }
        }

        return array_keys($labels);
    }

    public static function hasContactPlaceholders(string $template): bool
    {
        return self::requiredColumns($template) !== [];
    }

    public static function hasClientPlaceholders(string $template): bool
    {
        return in_array('businessname', self::extractKeys($template), true);
    }

    /**
     * Restrict a Contact query to contacts that have every placeholder column non-empty.
     */
    public static function applyRequiredFilter(Builder $query, string $template, string $table = 'contacts'): Builder
    {
        foreach (self::requiredColumns($template) as $column) {
            $qualified = "{$table}.{$column}";
            $query->whereNotNull($qualified)->whereRaw("TRIM({$qualified}) != ''");
        }

        return $query;
    }

    /**
     * True when `$contact` has a non-empty value for every contact placeholder in `$template`.
     */
    public static function hasAllRequired(object $contact, string $template): bool
    {
        foreach (self::requiredColumns($template) as $column) {
            $value = $contact->{$column} ?? null;

            if ($value === null || trim((string) $value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Render client-only placeholders (e.g. `{businessName}`) when no contact is needed.
     */
    public static function renderClientOnly(string $template, ?Client $client): string
    {
        if (! $client || ! self::hasClientPlaceholders($template)) {
            return $template;
        }

        $business = self::businessName($client);

        $text = preg_replace_callback('/\{\s*([A-Za-z_]+)\s*\}/', function (array $m) use ($business) {
            $key = self::normalize($m[1]);

            return $key === 'businessname' ? $business : $m[0];
        }, $template) ?? $template;

        return trim(preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text);
    }

    /**
     * Render all contact, client and extra placeholders in `$template`.
     *
     * @param  array<string, mixed>  $extraVars
     */
    public static function render(string $template, object $contact, ?Client $client = null, array $extraVars = []): string
    {
        $first = trim((string) ($contact->first_name ?? ''));
        $last = trim((string) ($contact->last_name ?? ''));
        $fullName = trim($first.' '.$last);
        $dob = trim((string) ($contact->date_of_birth ?? ''));
        $phone = trim((string) ($contact->phone_number ?? ''));

        $values = $extraVars + [
            'firstname' => $first,
            'lastname' => $last,
            'name' => $fullName,
            'fullname' => $fullName,
            'email' => trim((string) ($contact->email ?? '')),
            'gender' => trim((string) ($contact->gender ?? '')),
            'dob' => $dob,
            'dateofbirth' => $dob,
            'region' => trim((string) ($contact->region ?? '')),
            'city' => trim((string) ($contact->city ?? '')),
            'gpsaddress' => trim((string) ($contact->gps_address ?? '')),
            'address' => trim((string) ($contact->address ?? '')),
            'phonenumber' => $phone,
            'phone' => $phone,
            'businessname' => $client ? self::businessName($client) : '',
        ];

        $text = preg_replace_callback('/\{\s*([A-Za-z_]+)\s*\}/', function (array $m) use ($values) {
            $key = self::normalize($m[1]);

            return array_key_exists($key, $values) ? (string) $values[$key] : $m[0];
        }, $template) ?? $template;

        return trim(preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text);
    }

    protected static function businessName(Client $client): string
    {
        return trim((string) ($client->business_name ?: trim($client->first_name.' '.$client->last_name)));
    }

    /**
     * @return list<string>
     */
    protected static function extractKeys(string $template): array
    {
        if (! str_contains($template, '{')) {
            return [];
        }

        preg_match_all('/\{\s*([A-Za-z_]+)\s*\}/', $template, $matches);

        return array_values(array_unique(array_map(fn (string $raw) => self::normalize($raw), $matches[1] ?? [])));
    }

    protected static function normalize(string $raw): string
    {
        return strtolower(str_replace('_', '', $raw));
    }
}
