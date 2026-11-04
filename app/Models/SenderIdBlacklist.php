<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SenderIdBlacklist extends Model
{
    protected const CACHE_KEY = 'sender-id-blacklist:contains';

    protected $fillable = [
        'name',
        'normalized',
        'match_type',
        'source',
        'reason',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function normalize(string $name): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $name) ?? '');
    }

    /**
     * The blacklist entry that blocks this sender ID, if any.
     */
    public static function match(string $name): ?self
    {
        $normalized = self::normalize($name);

        if ($normalized === '') {
            return null;
        }

        $exact = static::query()->where('normalized', $normalized)->first();

        if ($exact) {
            return $exact;
        }

        $contains = Cache::remember(self::CACHE_KEY, 600, fn () => static::query()
            ->where('match_type', 'contains')
            ->get(['id', 'name', 'normalized', 'match_type', 'source', 'reason']));

        return $contains->first(fn (self $row) => str_contains($normalized, $row->normalized));
    }
}
