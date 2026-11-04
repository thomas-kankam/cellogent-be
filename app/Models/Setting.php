<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-editable key/value settings (values are JSON encoded).
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember("setting:{$key}", 300, function () use ($key) {
            $row = static::query()->where('key', $key)->value('value');

            return $row === null ? '__missing__' : $row;
        });

        return $value === '__missing__' ? $default : json_decode($value, true);
    }

    public static function put(string $key, mixed $value, ?int $adminId = null): void
    {
        static::query()->updateOrCreate(['key' => $key], [
            'value' => json_encode($value),
            'updated_by' => $adminId,
        ]);

        Cache::forget("setting:{$key}");
    }
}
